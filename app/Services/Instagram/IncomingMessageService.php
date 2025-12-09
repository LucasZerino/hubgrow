<?php

namespace App\Services\Instagram;

use App\Models\Inbox;
use App\Services\Base\IdempotencyTrait;
use Illuminate\Support\Facades\Log;

/**
 * Serviço IncomingMessageService
 * 
 * Processa mensagens recebidas do Instagram.
 * Aplica idempotência e delega para serviços específicos por tipo de mensagem.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\Instagram
 */
class IncomingMessageService
{
    use IdempotencyTrait;

    protected Inbox $inbox;
    protected array $params;
    protected ?\App\Models\ContactInbox $contactInbox = null;

    /**
     * Construtor
     * 
     * @param Inbox $inbox
     * @param array $params Parâmetros do webhook
     */
    public function __construct(Inbox $inbox, array $params)
    {
        $this->inbox = $inbox;
        $this->params = $params;
    }

    /**
     * Processa o evento recebido
     * 
     * @return void
     */
    public function process(): void
    {
        Log::info('[INSTAGRAM INCOMING] Iniciando processamento', [
            'params_structure' => array_keys($this->params),
            'has_first_entry' => isset($this->params[0]),
        ]);

        $entry = $this->params[0] ?? $this->params;
        $messaging = $entry['messaging'] ?? [];

        Log::info('[INSTAGRAM INCOMING] Extraindo messaging', [
            'entry_keys' => array_keys($entry),
            'messaging_count' => count($messaging),
            'has_messaging' => isset($entry['messaging']),
        ]);

        if (empty($messaging)) {
            Log::warning('[INSTAGRAM INCOMING] Nenhuma mensagem encontrada no payload', [
                'entry' => $entry,
                'params' => $this->params,
            ]);
            return;
        }

        foreach ($messaging as $index => $messageData) {
            Log::info("[INSTAGRAM INCOMING] Processando mensagem {$index}", [
                'message_data_keys' => array_keys($messageData),
            ]);
            $this->processMessage($messageData);
        }
    }

    /**
     * Processa uma mensagem individual
     * 
     * @param array $messageData
     * @return void
     */
    protected function processMessage(array $messageData): void
    {
        // Extrai o ID da mensagem para idempotência
        $messageId = $messageData['message']['mid'] ?? null;

        if (!$messageId) {
            Log::warning("[INSTAGRAM INCOMING] Message ID not found in payload", ['message_data' => $messageData]);
            return;
        }

        // Verifica se a mensagem já foi processada ou está em processamento
        if ($this->isMessageProcessed($messageId)) {
            Log::info("[INSTAGRAM INCOMING] Duplicate message ignored: {$messageId}");
            return;
        }

        if ($this->isMessageUnderProcess($messageId)) {
            Log::info("[INSTAGRAM INCOMING] Message already being processed: {$messageId}");
            return;
        }

        // Marca como em processamento ANTES de processar
        $this->markMessageAsProcessing($messageId);
        Log::info("[INSTAGRAM INCOMING] Marcando mensagem como em processamento: {$messageId}");

        // Log da estrutura do messageData para debug
        Log::info("[INSTAGRAM INCOMING] Estrutura do messageData", [
            'has_message' => isset($messageData['message']),
            'has_text' => isset($messageData['message']['text']),
            'has_reaction' => isset($messageData['reaction']),
            'has_read' => isset($messageData['read']),
            'message_keys' => isset($messageData['message']) ? array_keys($messageData['message']) : [],
            'message_data_keys' => array_keys($messageData),
        ]);

        try {
            $processed = false;

            // Processa mensagem de texto ou com anexos
            if (isset($messageData['message']['text']) || isset($messageData['message']['attachments'])) {
                Log::info("[INSTAGRAM INCOMING] Detectada mensagem de texto ou com anexos", [
                    'has_text' => isset($messageData['message']['text']),
                    'has_attachments' => isset($messageData['message']['attachments']),
                    'attachments_count' => isset($messageData['message']['attachments']) ? count($messageData['message']['attachments']) : 0,
                ]);
                $this->processTextMessage($messageData);
                $processed = true;
            }

            // Processa reações
            if (isset($messageData['reaction'])) {
                Log::info("[INSTAGRAM INCOMING] Detectada reação");
                $this->processReaction($messageData);
                $processed = true;
            }

            // Processa mensagem visualizada
            if (isset($messageData['read'])) {
                Log::info("[INSTAGRAM INCOMING] Detectada confirmação de leitura");
                $this->processReadReceipt($messageData);
                $processed = true;
            }
            
            // Se for echo (mensagem enviada pelo proprietário da página), processa como texto
            // Isso é importante para registrar mensagens enviadas fora do Chatwoot (ex: app do Instagram)
            if (!$processed && isset($messageData['message']['is_echo']) && $messageData['message']['is_echo'] === true) {
                Log::info("[INSTAGRAM INCOMING] Detectada mensagem echo (enviada pelo proprietário)", [
                    'message_id' => $messageId,
                ]);
                $this->processTextMessage($messageData);
                $processed = true;
            }

            if (!$processed) {
                Log::warning("[INSTAGRAM INCOMING] Nenhum tipo de mensagem detectado no payload", [
                    'message_data' => $messageData,
                ]);
            }
        } catch (\Exception $e) {
            Log::error("[INSTAGRAM INCOMING] Erro ao processar mensagem", [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        } finally {
            // Limpa a marca de processamento
            $this->clearMessageProcessing($messageId);
            Log::info("[INSTAGRAM INCOMING] Limpando marca de processamento: {$messageId}");
        }
    }

    /**
     * Processa mensagem de texto
     * Segue o padrão do Chatwoot: BaseMessageText.perform
     * 
     * @param array $messageData (messaging do webhook)
     * @return void
     */
    protected function processTextMessage(array $messageData): void
    {
        Log::info('[INSTAGRAM INCOMING] Processando mensagem de texto', [
            'channel' => 'instagram',
            'account_id' => $this->inbox->account_id,
            'inbox_id' => $this->inbox->id,
            'messaging_keys' => array_keys($messageData),
        ]);

        $channel = $this->inbox->channel;

        // Verifica se reautorização é necessária
        if ($channel && method_exists($channel, 'isReauthorizationRequired') && $channel->isReauthorizationRequired()) {
            Log::info("[INSTAGRAM INCOMING] Pular processamento de mensagem - reautorização necessária para inbox {$this->inbox->id}");
            return;
        }

        // Verifica se mensagem foi deletada
        if ($this->isMessageDeleted($messageData)) {
            $this->processUnsendMessage($messageData);
            return;
        }

        // Extrai IDs (connected_instagram_id, contact_id)
        [$connectedInstagramId, $contactId] = $this->extractInstagramAndContactIds($messageData);

        if (!$contactId) {
            Log::warning('[INSTAGRAM INCOMING] Contact ID não encontrado no messaging', [
                'messaging' => $messageData,
            ]);
            return;
        }

        // Verifica se é primeira mensagem do contato
        // Similar ao Chatwoot: contacts_first_message?
        if ($this->isContactsFirstMessage($contactId)) {
            Log::info('[INSTAGRAM INCOMING] Primeira mensagem do contato, buscando perfil e criando contact_inbox', [
                'contact_id' => $contactId,
            ]);
            // Busca perfil via API e cria contact_inbox usando channel.create_contact_inbox()
            $this->ensureContact($contactId);
        } else {
            // Se não é primeira mensagem, busca o contactInbox existente
            if (!$this->contactInbox) {
                $this->contactInbox = $this->inbox->contactInboxes()
                    ->where('source_id', $contactId)
                    ->first();
                
                if (!$this->contactInbox) {
                    Log::warning('[INSTAGRAM INCOMING] ContactInbox não encontrado para contact_id', [
                        'contact_id' => $contactId,
                        'inbox_id' => $this->inbox->id,
                    ]);
                    // Tenta criar o contato mesmo assim (pode ter sido deletado)
                    $this->ensureContact($contactId);
                } else {
                    Log::info('[INSTAGRAM INCOMING] ContactInbox encontrado', [
                        'contact_inbox_id' => $this->contactInbox->id,
                        'contact_id' => $this->contactInbox->contact_id,
                    ]);
                }
            }
        }

        // Cria mensagem usando MessageBuilder
        // Similar ao Chatwoot: create_message
        $this->createMessage($messageData);
    }

    /**
     * Extrai Instagram ID conectado e Contact ID do messaging
     * Similar ao Chatwoot: instagram_and_contact_ids
     * 
     * @param array $messaging
     * @return array [connected_instagram_id, contact_id]
     */
    protected function extractInstagramAndContactIds(array $messaging): array
    {
        if ($this->isAgentMessageViaEcho($messaging)) {
            // Echo: sender é o Instagram conectado, recipient é o contato
            return [
                $messaging['sender']['id'] ?? null,
                $messaging['recipient']['id'] ?? null,
            ];
        }

        // Mensagem normal: recipient é o Instagram conectado, sender é o contato
        return [
            $messaging['recipient']['id'] ?? null,
            $messaging['sender']['id'] ?? null,
        ];
    }

    /**
     * Verifica se é mensagem de echo (enviada pelo agente)
     * Similar ao Chatwoot: agent_message_via_echo?
     * 
     * @param array $messaging
     * @return bool
     */
    protected function isAgentMessageViaEcho(array $messaging): bool
    {
        return isset($messaging['message']['is_echo']) && $messaging['message']['is_echo'] === true;
    }

    /**
     * Verifica se mensagem foi deletada
     * Similar ao Chatwoot: message_is_deleted?
     * 
     * @param array $messaging
     * @return bool
     */
    protected function isMessageDeleted(array $messaging): bool
    {
        return isset($messaging['message']['is_deleted']) && $messaging['message']['is_deleted'] === true;
    }

    /**
     * Processa mensagem não enviada (deletada)
     * Similar ao Chatwoot: unsend_message
     * 
     * @param array $messaging
     * @return void
     */
    protected function processUnsendMessage(array $messaging): void
    {
        $messageId = $messaging['message']['mid'] ?? null;
        if (!$messageId) {
            return;
        }

        $messageToDelete = \App\Models\Message::where('inbox_id', $this->inbox->id)
            ->where('source_id', $messageId)
            ->first();

        if ($messageToDelete) {
            // Marca como deletada
            $messageToDelete->update([
                'content' => '[Mensagem deletada]',
                'deleted' => true,
            ]);
            Log::info('[INSTAGRAM INCOMING] Mensagem marcada como deletada', [
                'message_id' => $messageToDelete->id,
            ]);
        }
    }

    /**
     * Verifica se é primeira mensagem do contato
     * Similar ao Chatwoot: contacts_first_message?
     * 
     * @param string|null $instagramId
     * @return bool
     */
    protected function isContactsFirstMessage(?string $instagramId): bool
    {
        if (!$instagramId) {
            return false;
        }

        // Busca contact_inbox existente
        $this->contactInbox = $this->inbox->contactInboxes()
            ->where('source_id', $instagramId)
            ->first();

        // Se não existe contact_inbox e channel tem instagram_id, é primeira mensagem
        $channel = $this->inbox->channel;
        return $this->contactInbox === null && 
               $channel instanceof \App\Models\Channel\InstagramChannel && 
               $channel->instagram_id !== null;
    }

    /**
     * Garante que contato existe (busca perfil e cria contact_inbox)
     * Similar ao Chatwoot: ensure_contact
     * 
     * @param string $instagramId
     * @return void
     */
    protected function ensureContact(string $instagramId): void
    {
        Log::info('[INSTAGRAM INCOMING] Garantindo contato', ['instagram_id' => $instagramId]);

        // Busca informações do perfil via API
        $userInfo = $this->fetchInstagramUserProfile($instagramId);

        if (!$userInfo) {
            // Se não conseguiu buscar, cria contato desconhecido
            Log::warning('[INSTAGRAM INCOMING] Não foi possível buscar perfil, criando contato desconhecido', [
                'instagram_id' => $instagramId,
            ]);
            $userInfo = $this->createUnknownUser($instagramId);
        }

        if (!$userInfo) {
            return;
        }

        // Cria contact_inbox usando channel.create_contact_inbox()
        // Similar ao Chatwoot: @inbox.channel.create_contact_inbox(user['id'], user['name'])
        $channel = $this->inbox->channel;
        if ($channel instanceof \App\Models\Channel\InstagramChannel) {
            $name = $userInfo['name'] ?? $userInfo['username'] ?? "Unknown (IG: {$instagramId})";
            $this->contactInbox = $channel->createContactInbox($instagramId, $name);

            // Atualiza informações do perfil do Instagram
            if ($this->contactInbox && $this->contactInbox->contact) {
                $this->updateContactProfile($this->contactInbox->contact, $instagramId, $userInfo);
            }
        }
    }

    /**
     * Cria contato desconhecido
     * Similar ao Chatwoot: unknown_user
     * 
     * @param string $instagramId
     * @return array
     */
    protected function createUnknownUser(string $instagramId): array
    {
        return [
            'name' => "Unknown (IG: {$instagramId})",
            'id' => $instagramId,
        ];
    }

    /**
     * Cria mensagem usando MessageBuilder
     * Similar ao Chatwoot: create_message
     * 
     * @param array $messaging
     * @return void
     */
    protected function createMessage(array $messaging): void
    {
        error_log('[INSTAGRAM INCOMING] ========== CREATE MESSAGE CHAMADO ==========');
        error_log('[INSTAGRAM INCOMING] ContactInbox existe? ' . ($this->contactInbox ? 'SIM (ID=' . $this->contactInbox->id . ')' : 'NÃO'));
        error_log('[INSTAGRAM INCOMING] Inbox ID: ' . $this->inbox->id);
        error_log('[INSTAGRAM INCOMING] Account ID: ' . $this->inbox->account_id);
        error_log('[INSTAGRAM INCOMING] Message MID: ' . ($messaging['message']['mid'] ?? 'NULL'));
        
        if (!$this->contactInbox) {
            error_log('[INSTAGRAM INCOMING] ❌ ContactInbox não encontrado, não é possível criar mensagem');
            Log::warning('[INSTAGRAM INCOMING] ContactInbox não encontrado, não é possível criar mensagem', [
                'channel' => 'instagram',
                'account_id' => $this->inbox->account_id,
                'inbox_id' => $this->inbox->id,
                'messaging_keys' => array_keys($messaging),
                'sender_id' => $messaging['sender']['id'] ?? null,
                'recipient_id' => $messaging['recipient']['id'] ?? null,
            ]);
            return;
        }

        error_log('[INSTAGRAM INCOMING] ✅ ContactInbox encontrado, criando mensagem...');
        error_log('[INSTAGRAM INCOMING] ContactInbox ID: ' . $this->contactInbox->id);
        error_log('[INSTAGRAM INCOMING] Contact ID: ' . $this->contactInbox->contact_id);
        error_log('[INSTAGRAM INCOMING] Source ID: ' . $this->contactInbox->source_id);
        
        Log::info('[INSTAGRAM INCOMING] Criando mensagem via MessageBuilder', [
            'channel' => 'instagram',
            'account_id' => $this->inbox->account_id,
            'contact_inbox_id' => $this->contactInbox->id,
            'contact_id' => $this->contactInbox->contact_id,
            'source_id' => $this->contactInbox->source_id,
        ]);

        // Usa MessageBuilder para criar mensagem e conversa
        $outgoingEcho = $this->isAgentMessageViaEcho($messaging);
        error_log('[INSTAGRAM INCOMING] Outgoing Echo? ' . ($outgoingEcho ? 'SIM' : 'NÃO'));
        
        $builder = new \App\Builders\Messages\InstagramMessageBuilder(
            $messaging,
            $this->inbox,
            $outgoingEcho
        );

        // Passa o contactInbox criado para o builder (se existir)
        if ($this->contactInbox) {
            error_log('[INSTAGRAM INCOMING] Passando ContactInbox para o builder: ID=' . $this->contactInbox->id);
            $builder->setContactInbox($this->contactInbox);
        } else {
            error_log('[INSTAGRAM INCOMING] ⚠️ ContactInbox não foi criado antes de chamar o builder!');
        }

        error_log('[INSTAGRAM INCOMING] ========== CHAMANDO BUILDER->PERFORM() ==========');
        error_log('[INSTAGRAM INCOMING] Inbox ID: ' . $this->inbox->id);
        error_log('[INSTAGRAM INCOMING] Account ID: ' . $this->inbox->account_id);
        
        $message = $builder->perform();
        error_log('[INSTAGRAM INCOMING] Builder->perform() retornou: ' . ($message ? 'Message ID=' . $message->id : 'NULL'));

        if ($message) {
            error_log('[INSTAGRAM INCOMING] ✅ Mensagem criada: ID=' . $message->id . ' | Account ID=' . $message->account_id);
            error_log('[INSTAGRAM INCOMING] Aguardando Observer ser chamado...');
            
            Log::info('[INSTAGRAM INCOMING] Mensagem criada via MessageBuilder', [
                'channel' => 'instagram',
                'account_id' => $this->inbox->account_id,
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'content' => substr($message->content, 0, 100),
            ]);
        } else {
            error_log('[INSTAGRAM INCOMING] ⚠️ MessageBuilder retornou NULL - mensagem não foi criada');
            error_log('[INSTAGRAM INCOMING] Possíveis razões: mensagem já existe, conteúdo vazio, ou erro no builder');
            
            Log::warning('[INSTAGRAM INCOMING] MessageBuilder retornou null (mensagem não criada)', [
                'channel' => 'instagram',
                'account_id' => $this->inbox->account_id,
                'contact_inbox_id' => $this->contactInbox->id,
                'messaging_keys' => array_keys($messaging),
                'message_mid' => $messaging['message']['mid'] ?? null,
            ]);
        }
    }

    /**
     * Busca ou cria contato (método antigo - mantido para compatibilidade)
     * DEPRECATED: Use ensureContact() que segue o padrão do Chatwoot
     * 
     * @param string $instagramId
     * @return \App\Models\Contact
     * @deprecated Use ensureContact() instead
     */
    protected function findOrCreateContact(string $instagramId): \App\Models\Contact
    {
        Log::info('[INSTAGRAM INCOMING] Buscando/criando contato', ['instagram_id' => $instagramId]);

        // 1. Busca ContactInbox existente neste inbox
        $contactInbox = \App\Models\ContactInbox::where('inbox_id', $this->inbox->id)
            ->where('source_id', $instagramId)
            ->first();

        if ($contactInbox && $contactInbox->contact) {
            $contact = $contactInbox->contact;
            
            // Atualiza informações do perfil se disponível (busca async para não bloquear)
            // Nota: Em produção, isso poderia ser feito via job assíncrono
            $this->updateContactProfile($contact, $instagramId);
            
            return $contact;
        }

        // 2. Busca informações do perfil via API do Instagram
        $userInfo = $this->fetchInstagramUserProfile($instagramId);
        
        // 3. Busca contato existente por identifier, email, phone_number ou instagram_source_id
        $contact = $this->findExistingContact($instagramId, $userInfo);

        // 4. Se não encontrou, cria novo contato
        if (!$contact) {
            $contact = $this->createContact($instagramId, $userInfo);
        }

        // 5. Cria ContactInbox se não existir
        if (!$contactInbox) {
            $contactInbox = \App\Models\ContactInbox::create([
                'contact_id' => $contact->id,
                'inbox_id' => $this->inbox->id,
                'source_id' => $instagramId,
                'hmac_verified' => false,
            ]);
        }

        // 6. Atualiza informações do perfil do Instagram (já temos userInfo, não precisa buscar novamente)
        if ($userInfo) {
            $this->updateContactProfile($contact, $instagramId, $userInfo);
        }

        return $contact;
    }

    /**
     * Busca informações do perfil do Instagram via API
     * 
     * @param string $instagramId
     * @return array|null
     */
    protected function fetchInstagramUserProfile(string $instagramId): ?array
    {
        try {
            $channel = $this->inbox->channel;
            
            if (!$channel || !($channel instanceof \App\Models\Channel\InstagramChannel)) {
                Log::warning('[INSTAGRAM INCOMING] Channel não encontrado ou não é InstagramChannel', [
                    'channel_type' => $channel ? get_class($channel) : 'null',
                ]);
                return null;
            }

            $accessToken = $channel->getAccessToken();
            
            if (!$accessToken) {
                Log::warning('[INSTAGRAM INCOMING] Access token não disponível');
                return null;
            }

            $apiClient = new \App\Services\Instagram\InstagramApiClient($accessToken);
            // Passa o channel para que o API client possa marcar erro de autorização se necessário
            $userInfo = $apiClient->fetchInstagramUser($instagramId, $accessToken, $channel);

            if ($userInfo) {
                Log::info('[INSTAGRAM INCOMING] Perfil do Instagram buscado com sucesso', [
                    'instagram_id' => $instagramId,
                    'name' => $userInfo['name'] ?? null,
                    'username' => $userInfo['username'] ?? null,
                ]);
            } else {
                Log::info('[INSTAGRAM INCOMING] Não foi possível buscar perfil do Instagram', [
                    'instagram_id' => $instagramId,
                ]);
            }

            return $userInfo;
        } catch (\Exception $e) {
            Log::warning("[INSTAGRAM INCOMING] Erro ao buscar perfil do Instagram: {$e->getMessage()}", [
                'instagram_id' => $instagramId,
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Busca contato existente por diferentes critérios
     * Segue a mesma lógica do Chatwoot: identifier, email, phone_number, instagram_source_id
     * 
     * @param string $instagramId
     * @param array|null $userInfo
     * @return \App\Models\Contact|null
     */
    protected function findExistingContact(string $instagramId, ?array $userInfo): ?\App\Models\Contact
    {
        $accountId = $this->inbox->account_id;

        // 1. Busca por identifier_instagram (prioridade)
        $contact = \App\Models\Contact::where('account_id', $accountId)
            ->where('identifier_instagram', $instagramId)
            ->first();

        if ($contact) {
            Log::info('[INSTAGRAM INCOMING] Contato encontrado por identifier_instagram', [
                'contact_id' => $contact->id,
                'instagram_id' => $instagramId,
            ]);
            return $contact;
        }


        // 2. Busca por email (se disponível no userInfo)
        if ($userInfo && isset($userInfo['email']) && $userInfo['email']) {
            // Nota: Instagram API não retorna email, mas deixamos aqui para compatibilidade futura
            // $contact = \App\Models\Contact::where('account_id', $accountId)
            //     ->where('email', $userInfo['email'])
            //     ->first();
        }

        // 3. Busca por phone_number (se disponível)
        if ($userInfo && isset($userInfo['phone_number']) && $userInfo['phone_number']) {
            // Nota: Instagram API não retorna phone_number, mas deixamos aqui para compatibilidade
        }

        // 4. Busca por instagram_source_id em outros canais (Facebook, etc.)
        // Similar ao Chatwoot: busca ContactInbox de FacebookPage com mesmo source_id
        // Nota: Por enquanto só buscamos em InstagramChannel, mas podemos expandir para FacebookPage no futuro
        $existingContactInbox = \App\Models\ContactInbox::join('inboxes', 'contact_inboxes.inbox_id', '=', 'inboxes.id')
            ->where('contact_inboxes.source_id', $instagramId)
            ->where('inboxes.account_id', $accountId)
            ->where('inboxes.channel_type', '!=', \App\Models\Channel\InstagramChannel::class)
            ->select('contact_inboxes.*')
            ->first();

        if ($existingContactInbox && $existingContactInbox->contact) {
            Log::info('[INSTAGRAM INCOMING] Contato encontrado por instagram_source_id em outro canal', [
                'contact_id' => $existingContactInbox->contact->id,
                'channel_type' => $existingContactInbox->inbox->channel_type ?? 'unknown',
            ]);
            return $existingContactInbox->contact;
        }

        return null;
    }

    /**
     * Cria novo contato
     * 
     * @param string $instagramId
     * @param array|null $userInfo
     * @return \App\Models\Contact
     */
    protected function createContact(string $instagramId, ?array $userInfo): \App\Models\Contact
    {
        // Determina nome do contato
        $name = $userInfo['name'] ?? null;
        
        if (!$name) {
            // Se não tem nome, tenta usar username
            $name = $userInfo['username'] ?? null;
        }
        
        if (!$name) {
            // Fallback: nome genérico
            $name = "Unknown (IG: {$instagramId})";
        }

        Log::info('[INSTAGRAM INCOMING] Criando novo contato', [
            'name' => $name,
            'instagram_id' => $instagramId,
        ]);

        $contact = \App\Models\Contact::create([
            'account_id' => $this->inbox->account_id,
            'name' => $name,
            'email' => null,
            'phone_number' => null,
            'identifier_instagram' => $instagramId,
            'additional_attributes' => [],
            'custom_attributes' => [],
        ]);

        return $contact;
    }

    /**
     * Atualiza informações do perfil do Instagram no contato
     * 
     * @param \App\Models\Contact $contact
     * @param string $instagramId
     * @param array|null $userInfo
     * @return void
     */
    protected function updateContactProfile(\App\Models\Contact $contact, string $instagramId, ?array $userInfo = null): void
    {
        // Se userInfo não foi fornecido, tenta buscar
        if ($userInfo === null) {
            $userInfo = $this->fetchInstagramUserProfile($instagramId);
        }

        if (!$userInfo) {
            return;
        }

        $additionalAttributes = $contact->additional_attributes ?? [];
        $updated = false;

        // Atualiza nome se disponível e diferente
        if (isset($userInfo['name']) && $userInfo['name'] && $contact->name !== $userInfo['name']) {
            $contact->name = $userInfo['name'];
            $updated = true;
        }

        // Atualiza avatar_url se disponível
        if (isset($userInfo['profile_pic']) && $userInfo['profile_pic']) {
            $contact->avatar_url = $userInfo['profile_pic'];
            $updated = true;
        }

        // Atualiza additional_attributes com informações do Instagram
        if (isset($userInfo['username']) && $userInfo['username']) {
            $additionalAttributes['social_profiles'] = $additionalAttributes['social_profiles'] ?? [];
            $additionalAttributes['social_profiles']['instagram'] = $userInfo['username'];
            $additionalAttributes['social_instagram_user_name'] = $userInfo['username'];
            $updated = true;
        }

        // Adiciona campos opcionais se disponíveis
        $optionalFields = [
            'follower_count' => 'social_instagram_follower_count',
            'is_user_follow_business' => 'social_instagram_is_user_follow_business',
            'is_business_follow_user' => 'social_instagram_is_business_follow_user',
            'is_verified_user' => 'social_instagram_is_verified_user',
        ];

        foreach ($optionalFields as $field => $attrKey) {
            if (isset($userInfo[$field]) && $userInfo[$field] !== null) {
                $additionalAttributes[$attrKey] = $userInfo[$field];
                $updated = true;
            }
        }

        if ($updated) {
            $contact->additional_attributes = $additionalAttributes;
            $contact->save();
            
            Log::info('[INSTAGRAM INCOMING] Perfil do contato atualizado', [
                'contact_id' => $contact->id,
                'name' => $contact->name,
                'username' => $userInfo['username'] ?? null,
            ]);
        }
    }


    /**
     * Processa reação
     * 
     * @param array $messageData
     * @return void
     */
    protected function processReaction(array $messageData): void
    {
        // TODO: Implementar processamento de reações
        Log::info("[INSTAGRAM] Reaction received", [
            'inbox_id' => $this->inbox->id,
        ]);
    }

    /**
     * Processa confirmação de leitura
     * 
     * @param array $messageData
     * @return void
     */
    protected function processReadReceipt(array $messageData): void
    {
        // TODO: Implementar atualização de status de leitura
        Log::info("[INSTAGRAM] Read receipt received", [
            'inbox_id' => $this->inbox->id,
        ]);
    }
}


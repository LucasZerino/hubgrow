<?php

namespace App\Services\Facebook;

use App\Models\Inbox;
use App\Services\Base\IdempotencyTrait;
use Illuminate\Support\Facades\Log;

/**
 * Serviço IncomingMessageService
 * 
 * Processa mensagens recebidas do Facebook Messenger.
 * Aplica idempotência e delega para serviços específicos por tipo de mensagem.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\Facebook
 */
class IncomingMessageService
{
    use IdempotencyTrait;

    protected Inbox $inbox;
    protected MessageParser $parser;
    protected ?\App\Models\ContactInbox $contactInbox = null;

    /**
     * Construtor
     * 
     * @param Inbox $inbox
     * @param MessageParser $parser Parser do payload
     */
    public function __construct(Inbox $inbox, MessageParser $parser)
    {
        $this->inbox = $inbox;
        $this->parser = $parser;
    }

    /**
     * Processa o evento recebido
     * 
     * @return void
     */
    public function process(): void
    {
        // Verifica idempotência
        $messageId = $this->parser->getMessageId();
        if ($messageId && $this->isMessageProcessed($messageId)) {
            Log::info("[FACEBOOK] Duplicate message ignored: {$messageId}");
            return;
        }

        if ($messageId && $this->isMessageUnderProcess($messageId)) {
            Log::info("[FACEBOOK] Message already being processed: {$messageId}");
            return;
        }

        // Marca como em processamento
        if ($messageId) {
            $this->markMessageAsProcessing($messageId);
        }

        try {
            // Processa mensagem (texto ou attachments)
            $hasContent = $this->parser->getContent() !== null;
            $hasAttachments = !empty($this->parser->getAttachments());
            
            if ($hasContent || $hasAttachments) {
                $this->processTextMessage();
            }

            // Processa delivery status
            if ($this->parser->getDelivery()) {
                $this->processDelivery();
            }

            // Processa read status
            if ($this->parser->getRead()) {
                $this->processReadReceipt();
            }
        } catch (\Exception $e) {
            Log::error("[FACEBOOK] Erro ao processar mensagem", [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        } finally {
            // Limpa a marca de processamento
            if ($messageId) {
                $this->clearMessageProcessing($messageId);
            }
        }
    }

    /**
     * Processa mensagem de texto ou attachments
     * Similar ao Instagram: processTextMessage
     * 
     * @return void
     */
    protected function processTextMessage(): void
    {
        $hasContent = $this->parser->getContent() !== null;
        $hasAttachments = !empty($this->parser->getAttachments());
        
        Log::info("[FACEBOOK] Processando mensagem", [
            'inbox_id' => $this->inbox->id,
            'sender_id' => $this->parser->getSenderId(),
            'has_content' => $hasContent,
            'has_attachments' => $hasAttachments,
            'content' => $this->parser->getContent(),
            'attachments_count' => $hasAttachments ? count($this->parser->getAttachments()) : 0,
        ]);

        $channel = $this->inbox->channel;

        // Verifica se reautorização é necessária
        if ($channel && method_exists($channel, 'isReauthorizationRequired') && $channel->isReauthorizationRequired()) {
            Log::info("[FACEBOOK] Pular processamento de mensagem - reautorização necessária para inbox {$this->inbox->id}");
            return;
        }

        // Verifica se é echo (mensagem enviada por nós)
        if ($this->parser->isEcho()) {
            Log::info("[FACEBOOK] Mensagem echo ignorada");
            return;
        }

        // Extrai sender_id (contato)
        $senderId = $this->parser->getSenderId();
        if (!$senderId) {
            Log::warning('[FACEBOOK] Sender ID não encontrado');
            return;
        }

        // Verifica se é primeira mensagem do contato
        if ($this->isContactsFirstMessage($senderId)) {
            Log::info('[FACEBOOK] Primeira mensagem do contato, criando contact_inbox', [
                'sender_id' => $senderId,
            ]);
            $this->ensureContact($senderId);
        } else {
            // Se não é primeira mensagem, busca o contactInbox existente
            if (!$this->contactInbox) {
                $this->contactInbox = $this->inbox->contactInboxes()
                    ->with('contact') // Garante que o relacionamento contact está carregado
                    ->where('source_id', $senderId)
                    ->first();
                
                if (!$this->contactInbox) {
                    Log::warning('[FACEBOOK] ContactInbox não encontrado para sender_id', [
                        'sender_id' => $senderId,
                        'inbox_id' => $this->inbox->id,
                    ]);
                    // Tenta criar o contato mesmo assim (pode ter sido deletado)
                    $this->ensureContact($senderId);
                } else {
                    Log::info('[FACEBOOK] ContactInbox encontrado', [
                        'contact_inbox_id' => $this->contactInbox->id,
                        'contact_id' => $this->contactInbox->contact_id,
                        'contact_loaded' => $this->contactInbox->relationLoaded('contact'),
                    ]);
                }
            }
        }

        // Cria mensagem usando MessageBuilder
        $this->createMessage();
    }

    /**
     * Verifica se é primeira mensagem do contato
     * 
     * @param string|null $facebookId
     * @return bool
     */
    protected function isContactsFirstMessage(?string $facebookId): bool
    {
        if (!$facebookId) {
            return false;
        }

        // Busca contact_inbox existente
        $this->contactInbox = $this->inbox->contactInboxes()
            ->where('source_id', $facebookId)
            ->first();

        // Se não existe contact_inbox e channel tem page_id, é primeira mensagem
        $channel = $this->inbox->channel;
        return $this->contactInbox === null && 
               $channel instanceof \App\Models\Channel\FacebookChannel && 
               $channel->page_id !== null;
    }

    /**
     * Garante que contato existe (busca perfil e cria contact_inbox)
     * Similar ao Instagram: ensureContact
     * 
     * @param string $facebookId
     * @return void
     */
    protected function ensureContact(string $facebookId): void
    {
        Log::info('[FACEBOOK] Garantindo contato', ['facebook_id' => $facebookId]);

        // Tenta buscar informações do perfil via API com retry
        $userInfo = null;
        $maxRetries = 3;
        $retryDelay = 1; // segundos
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            Log::info('[FACEBOOK] Tentativa de buscar perfil', [
                'facebook_id' => $facebookId,
                'attempt' => $attempt,
                'max_retries' => $maxRetries,
            ]);
            
            $userInfo = $this->fetchFacebookUserProfile($facebookId);
            
            if ($userInfo && isset($userInfo['name']) && !empty($userInfo['name'])) {
                Log::info('[FACEBOOK] Perfil obtido com sucesso', [
                    'facebook_id' => $facebookId,
                    'name' => $userInfo['name'],
                    'attempt' => $attempt,
                ]);
                break; // Sucesso, sai do loop
            }
            
            if ($attempt < $maxRetries) {
                Log::warning('[FACEBOOK] Tentativa falhou, aguardando antes de tentar novamente', [
                    'facebook_id' => $facebookId,
                    'attempt' => $attempt,
                    'retry_delay' => $retryDelay,
                ]);
                sleep($retryDelay);
                $retryDelay *= 2; // Exponential backoff
            }
        }

        // Se ainda não conseguiu buscar, cria contato desconhecido
        if (!$userInfo || empty($userInfo['name'])) {
            Log::warning('[FACEBOOK] Não foi possível buscar perfil após todas as tentativas, criando contato desconhecido', [
                'facebook_id' => $facebookId,
                'attempts' => $maxRetries,
                'userInfo' => $userInfo,
            ]);
            $userInfo = $this->createUnknownUser($facebookId);
        }

        if (!$userInfo) {
            return;
        }

        // Cria contact_inbox usando channel.create_contact_inbox()
        $channel = $this->inbox->channel;
        if ($channel instanceof \App\Models\Channel\FacebookChannel) {
            // Prioriza name, depois first_name, depois first_name + last_name, por último Unknown
            $name = $userInfo['name'] 
                ?? ($userInfo['first_name'] ?? null)
                ?? (($userInfo['first_name'] ?? '') . ' ' . ($userInfo['last_name'] ?? ''))
                ?? "Unknown (FB: {$facebookId})";
            
            // Remove espaços extras
            $name = trim($name);
            
            // Se ainda estiver vazio ou for só espaços, usa Unknown
            if (empty($name)) {
                $name = "Unknown (FB: {$facebookId})";
            }
            
            Log::info('[FACEBOOK] Criando ContactInbox com nome', [
                'facebook_id' => $facebookId,
                'name' => $name,
                'userInfo_keys' => array_keys($userInfo),
            ]);
            
            $this->contactInbox = $channel->createContactInbox($facebookId, $name);

            // Garante que o relacionamento contact está carregado
            if ($this->contactInbox && !$this->contactInbox->relationLoaded('contact')) {
                $this->contactInbox->load('contact');
            }

            // Atualiza informações do perfil
            if ($this->contactInbox && $this->contactInbox->contact) {
                $this->updateContactProfile($this->contactInbox->contact, $facebookId, $userInfo);
                
                // Se o nome ainda é "Unknown", tenta buscar novamente via API
                if (str_starts_with($this->contactInbox->contact->name, 'Unknown (FB:')) {
                    Log::info('[FACEBOOK] Contato criado com nome Unknown, tentando buscar perfil novamente', [
                        'contact_id' => $this->contactInbox->contact->id,
                        'facebook_id' => $facebookId,
                    ]);
                    $this->tryUpdateUnknownContactName($this->contactInbox->contact, $facebookId);
                }
            }
            
            Log::info('[FACEBOOK] ContactInbox criado/atualizado', [
                'contact_inbox_id' => $this->contactInbox->id,
                'contact_id' => $this->contactInbox->contact_id,
                'contact_loaded' => $this->contactInbox->relationLoaded('contact'),
            ]);
        }
    }

    /**
     * Cria contato desconhecido
     * 
     * @param string $facebookId
     * @return array
     */
    protected function createUnknownUser(string $facebookId): array
    {
        return [
            'name' => "Unknown (FB: {$facebookId})",
            'id' => $facebookId,
        ];
    }

    /**
     * Busca informações do perfil do Facebook via API
     * 
     * @param string $facebookId
     * @return array|null
     */
    protected function fetchFacebookUserProfile(string $facebookId): ?array
    {
        try {
            $channel = $this->inbox->channel;
            
            if (!$channel || !($channel instanceof \App\Models\Channel\FacebookChannel)) {
                Log::warning('[FACEBOOK] Channel não encontrado ou não é FacebookChannel', [
                    'channel_type' => $channel ? get_class($channel) : 'null',
                ]);
                return null;
            }

            if (empty($channel->page_access_token)) {
                Log::warning('[FACEBOOK] page_access_token não configurado', [
                    'channel_id' => $channel->id,
                ]);
                return null;
            }

            Log::info('[FACEBOOK] Buscando perfil do Facebook via API', [
                'facebook_id' => $facebookId,
                'channel_id' => $channel->id,
                'has_token' => !empty($channel->page_access_token),
            ]);

            $apiClient = new \App\Services\Facebook\FacebookApiClient($channel->page_access_token);
            $userInfo = $apiClient->fetchUserProfile($facebookId, $channel->page_access_token);

            if ($userInfo) {
                Log::info('[FACEBOOK] Perfil do Facebook buscado com sucesso', [
                    'facebook_id' => $facebookId,
                    'name' => $userInfo['name'] ?? null,
                    'first_name' => $userInfo['first_name'] ?? null,
                    'last_name' => $userInfo['last_name'] ?? null,
                ]);
            } else {
                Log::warning('[FACEBOOK] Perfil do Facebook não encontrado ou API retornou null', [
                    'facebook_id' => $facebookId,
                ]);
            }

            return $userInfo;
        } catch (\Exception $e) {
            Log::error("[FACEBOOK] Erro ao buscar perfil do Facebook: {$e->getMessage()}", [
                'facebook_id' => $facebookId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Atualiza informações do perfil do contato
     * 
     * @param \App\Models\Contact $contact
     * @param string $facebookId
     * @param array $userInfo
     * @return void
     */
    protected function updateContactProfile(\App\Models\Contact $contact, string $facebookId, array $userInfo): void
    {
        $updates = [];

        // Atualiza nome se disponível e diferente
        if (isset($userInfo['name']) && $userInfo['name'] && $contact->name !== $userInfo['name']) {
            $updates['name'] = $userInfo['name'];
        }

        // Atualiza avatar_url se disponível
        if (isset($userInfo['profile_pic']) && $userInfo['profile_pic'] && $contact->avatar_url !== $userInfo['profile_pic']) {
            $updates['avatar_url'] = $userInfo['profile_pic'];
        }

        // Atualiza additional_attributes com informações do Facebook
        $additionalAttributes = $contact->additional_attributes ?? [];
        $attributesUpdated = false;

        if (isset($userInfo['first_name']) || isset($userInfo['last_name'])) {
            $additionalAttributes['facebook_first_name'] = $userInfo['first_name'] ?? null;
            $additionalAttributes['facebook_last_name'] = $userInfo['last_name'] ?? null;
            $attributesUpdated = true;
        }

        if (isset($userInfo['id'])) {
            $additionalAttributes['facebook_id'] = $userInfo['id'];
            $attributesUpdated = true;
        }

        if ($attributesUpdated) {
            $updates['additional_attributes'] = $additionalAttributes;
        }

        if (!empty($updates)) {
            $contact->update($updates);
            Log::info('[FACEBOOK] Perfil do contato atualizado', [
                'contact_id' => $contact->id,
                'updates' => $updates,
            ]);
        }
    }

    /**
     * Tenta atualizar o nome de um contato "Unknown" buscando o perfil novamente
     * 
     * @param \App\Models\Contact $contact
     * @param string $facebookId
     * @return void
     */
    protected function tryUpdateUnknownContactName(\App\Models\Contact $contact, string $facebookId): void
    {
        try {
            Log::info('[FACEBOOK] Tentando atualizar nome Unknown do contato', [
                'contact_id' => $contact->id,
                'facebook_id' => $facebookId,
                'current_name' => $contact->name,
            ]);
            
            // Tenta buscar o perfil com retry
            $userInfo = null;
            $maxRetries = 2;
            
            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                $userInfo = $this->fetchFacebookUserProfile($facebookId);
                
                if ($userInfo && (isset($userInfo['name']) || isset($userInfo['first_name']))) {
                    break;
                }
                
                if ($attempt < $maxRetries) {
                    sleep(1);
                }
            }
            
            if ($userInfo) {
                // Prioriza name, depois first_name, depois first_name + last_name
                $newName = $userInfo['name'] 
                    ?? ($userInfo['first_name'] ?? null)
                    ?? (trim(($userInfo['first_name'] ?? '') . ' ' . ($userInfo['last_name'] ?? '')));
                
                if (!empty($newName) && $newName !== $contact->name) {
                    $oldName = $contact->name;
                    $contact->update(['name' => $newName]);
                    
                    Log::info('[FACEBOOK] Nome do contato Unknown atualizado com sucesso', [
                        'contact_id' => $contact->id,
                        'old_name' => $oldName,
                        'new_name' => $newName,
                        'facebook_id' => $facebookId,
                    ]);
                    
                    // Atualiza também o avatar se disponível
                    if (isset($userInfo['profile_pic']) && $userInfo['profile_pic']) {
                        $contact->update(['avatar_url' => $userInfo['profile_pic']]);
                    }
                } else {
                    Log::warning('[FACEBOOK] Perfil obtido mas nome vazio ou igual ao atual', [
                        'contact_id' => $contact->id,
                        'facebook_id' => $facebookId,
                        'new_name' => $newName,
                        'current_name' => $contact->name,
                    ]);
                }
            } else {
                Log::warning('[FACEBOOK] Não foi possível buscar perfil para atualizar nome Unknown', [
                    'contact_id' => $contact->id,
                    'facebook_id' => $facebookId,
                    'attempts' => $maxRetries,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('[FACEBOOK] Erro ao tentar atualizar nome Unknown', [
                'contact_id' => $contact->id,
                'facebook_id' => $facebookId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Cria mensagem usando MessageBuilder
     * Similar ao Instagram: createMessage
     * 
     * @return void
     */
    protected function createMessage(): void
    {
        if (!$this->contactInbox) {
            Log::warning('[FACEBOOK] ContactInbox não encontrado, não é possível criar mensagem');
            return;
        }

        Log::info('[FACEBOOK] Criando mensagem via MessageBuilder', [
            'contact_inbox_id' => $this->contactInbox->id,
            'contact_id' => $this->contactInbox->contact_id,
        ]);

        // Usa MessageBuilder para criar conversa e mensagem
        $outgoingEcho = $this->parser->isEcho();
        
        $builder = new \App\Builders\Messages\FacebookMessageBuilder(
            $this->parser->getMessaging(),
            $this->inbox,
            $outgoingEcho
        );

        // Passa o contactInbox criado para o builder (como o Instagram faz)
        $builder->setContactInbox($this->contactInbox);

        $message = $builder->perform();

        if ($message) {
            Log::info('[FACEBOOK] Mensagem criada via MessageBuilder', [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
            ]);
        } else {
            Log::warning('[FACEBOOK] MessageBuilder retornou null (mensagem não criada)');
        }
    }

    /**
     * Processa delivery status
     * 
     * @return void
     */
    protected function processDelivery(): void
    {
        $delivery = $this->parser->getDelivery();
        if (!$delivery) {
            return;
        }

        $watermark = $this->parser->getDeliveryWatermark();
        if (!$watermark) {
            return;
        }

        Log::info("[FACEBOOK] Processando delivery status", [
            'inbox_id' => $this->inbox->id,
            'watermark' => $watermark,
        ]);

        // Atualiza status de entrega das mensagens
        // Similar ao Chatwoot: update_delivery_status
        $this->inbox->conversations()
            ->whereHas('messages', function ($query) use ($watermark) {
                $query->where('source_id', '<=', (string)$watermark)
                      ->where('message_type', 1); // Message::TYPE_OUTGOING
            })
            ->get()
            ->each(function ($conversation) {
                // Marca mensagens como entregues
                $conversation->messages()
                    ->where('message_type', 1) // Message::TYPE_OUTGOING
                    ->whereNull('external_source_id_sent_at')
                    ->update(['external_source_id_sent_at' => now()]);
            });
    }

    /**
     * Processa confirmação de leitura
     * 
     * @return void
     */
    protected function processReadReceipt(): void
    {
        $read = $this->parser->getRead();
        if (!$read) {
            return;
        }

        $watermark = $this->parser->getReadWatermark();
        if (!$watermark) {
            return;
        }

        Log::info("[FACEBOOK] Processando read receipt", [
            'inbox_id' => $this->inbox->id,
            'watermark' => $watermark,
        ]);

        // Atualiza watermark de leitura
        // Similar ao Chatwoot: update_read_status
        $this->inbox->conversations()
            ->whereHas('messages', function ($query) use ($watermark) {
                $query->where('source_id', '<=', (string)$watermark)
                      ->where('message_type', 1); // Message::TYPE_OUTGOING
            })
            ->get()
            ->each(function ($conversation) use ($watermark) {
                // Atualiza conversa com watermark de leitura
                $conversation->update([
                    'additional_attributes' => array_merge(
                        $conversation->additional_attributes ?? [],
                        ['read_watermark' => $watermark]
                    ),
                ]);
            });
    }
}


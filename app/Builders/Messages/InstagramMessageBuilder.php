<?php

namespace App\Builders\Messages;

use App\Models\Contact;
use App\Models\ContactInbox;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * InstagramMessageBuilder
 * 
 * Constrói mensagens do Instagram seguindo o padrão do Chatwoot.
 * Similar ao Chatwoot: Messages::Instagram::BaseMessageBuilder
 * 
 * @package App\Builders\Messages
 */
class InstagramMessageBuilder
{
    protected array $messaging;
    protected Inbox $inbox;
    protected bool $outgoingEcho;

    protected ?Contact $contact = null;
    protected ?ContactInbox $contactInbox = null;
    protected ?Conversation $conversation = null;
    protected ?Message $message = null;

    /**
     * Define o contactInbox (usado quando já foi criado pelo IncomingMessageService)
     * 
     * @param ContactInbox $contactInbox
     * @return self
     */
    public function setContactInbox(ContactInbox $contactInbox): self
    {
        $this->contactInbox = $contactInbox;
        $this->contact = $contactInbox->contact;
        return $this;
    }

    /**
     * Construtor
     * 
     * @param array $messaging Dados do webhook do Instagram
     * @param Inbox $inbox Inbox associado
     * @param bool $outgoingEcho Se é mensagem de echo (enviada pelo agente)
     */
    public function __construct(array $messaging, Inbox $inbox, bool $outgoingEcho = false)
    {
        $this->messaging = $messaging;
        $this->inbox = $inbox;
        $this->outgoingEcho = $outgoingEcho;
    }

    /**
     * Executa a construção da mensagem
     * Similar ao Chatwoot: perform
     * 
     * @return Message|null
     */
    public function perform(): ?Message
    {
        $channel = $this->inbox->channel;
        
        // Verifica se reautorização é necessária
        if ($channel && method_exists($channel, 'isReauthorizationRequired') && $channel->isReauthorizationRequired()) {
            Log::info("[INSTAGRAM MESSAGE BUILDER] Pular processamento de mensagem - reautorização necessária para inbox {$this->inbox->id}");
            return null;
        }

        try {
            return DB::transaction(function () {
                return $this->buildMessage();
            });
        } catch (\Exception $e) {
            $this->handleError($e);
            return null;
        }
    }

    /**
     * Constrói a mensagem
     * Similar ao Chatwoot: build_message
     * 
     * @return Message|null
     */
    protected function buildMessage(): ?Message
    {
        $messageIdentifier = $this->getMessageIdentifier();
        error_log('[INSTAGRAM MESSAGE BUILDER] ========== BUILD MESSAGE CHAMADO ==========');
        error_log('[INSTAGRAM MESSAGE BUILDER] Message Identifier (source_id): ' . ($messageIdentifier ?? 'NULL'));
        
        // Verifica se mensagem já existe (duplicatas)
        if ($this->messageAlreadyExists()) {
            error_log('[INSTAGRAM MESSAGE BUILDER] ⚠️ Mensagem já existe, PULANDO criação');
            error_log('[INSTAGRAM MESSAGE BUILDER] Source ID: ' . $messageIdentifier);
            Log::info('[INSTAGRAM MESSAGE BUILDER] Mensagem já existe, pulando', [
                'source_id' => $messageIdentifier,
            ]);
            return null;
        }
        
        error_log('[INSTAGRAM MESSAGE BUILDER] ✅ Mensagem não existe, prosseguindo com criação...');

        $content = $this->getMessageContent();
        
        // Se não tem conteúdo e todos os arquivos são não suportados, pula
        if (empty($content) && $this->allUnsupportedFiles()) {
            Log::info('[INSTAGRAM MESSAGE BUILDER] Conteúdo vazio e arquivos não suportados, pulando');
            return null;
        }

        // Busca ou cria conversa
        $conversation = $this->getConversation();

        if (!$conversation) {
            Log::warning('[INSTAGRAM MESSAGE BUILDER] Não foi possível obter conversa');
            return null;
        }

        // Cria mensagem
        Log::info('[INSTAGRAM MESSAGE BUILDER] Criando mensagem...', [
            'conversation_id' => $conversation->id,
            'account_id' => $conversation->account_id,
        ]);
        
        error_log('[INSTAGRAM MESSAGE BUILDER] ========== CRIANDO MENSAGEM ==========');
        error_log('[INSTAGRAM MESSAGE BUILDER] Conversation ID: ' . $conversation->id);
        error_log('[INSTAGRAM MESSAGE BUILDER] Account ID: ' . $conversation->account_id);
        
        Log::info('[INSTAGRAM MESSAGE BUILDER] Criando mensagem via create()...', [
            'conversation_id' => $conversation->id,
            'account_id' => $conversation->account_id,
        ]);
        
        $this->message = $conversation->messages()->create($this->getMessageParams());
        
        error_log('[INSTAGRAM MESSAGE BUILDER] ✅ Mensagem criada: ID=' . $this->message->id . ' | Account ID=' . $this->message->account_id);
        error_log('[INSTAGRAM MESSAGE BUILDER] Verificando se Observer foi chamado...');
        
        Log::info('[INSTAGRAM MESSAGE BUILDER] Mensagem criada, aguardando Observer...', [
            'message_id' => $this->message->id,
            'account_id' => $this->message->account_id,
        ]);

        Log::info('[INSTAGRAM MESSAGE BUILDER] ✅ Mensagem criada com sucesso', [
            'message_id' => $this->message->id,
            'conversation_id' => $conversation->id,
            'account_id' => $this->message->account_id,
            'content' => substr($this->message->content, 0, 50),
        ]);

        // Processa anexos
        $this->processAttachments();

        return $this->message;
    }

    /**
     * Retorna o contato
     * Similar ao Chatwoot: contact
     * 
     * @return Contact|null
     */
    protected function getContact(): ?Contact
    {
        // Se já foi definido via setContactInbox(), retorna direto
        if ($this->contact && $this->contactInbox) {
            error_log('[INSTAGRAM MESSAGE BUILDER] ✅ Contato já definido via setContactInbox: Contact ID=' . $this->contact->id);
            return $this->contact;
        }

        $messageSourceId = $this->getMessageSourceId();
        
        error_log('[INSTAGRAM MESSAGE BUILDER] ========== BUSCANDO CONTATO ==========');
        error_log('[INSTAGRAM MESSAGE BUILDER] Message Source ID: ' . ($messageSourceId ?? 'NULL'));
        error_log('[INSTAGRAM MESSAGE BUILDER] Outgoing Echo: ' . ($this->outgoingEcho ? 'SIM' : 'NÃO'));
        error_log('[INSTAGRAM MESSAGE BUILDER] Sender ID: ' . ($this->getSenderId() ?? 'NULL'));
        error_log('[INSTAGRAM MESSAGE BUILDER] Recipient ID: ' . ($this->getRecipientId() ?? 'NULL'));
        error_log('[INSTAGRAM MESSAGE BUILDER] Inbox ID: ' . $this->inbox->id);
        
        Log::info('[INSTAGRAM MESSAGE BUILDER] Buscando contato', [
            'channel' => 'instagram',
            'account_id' => $this->inbox->account_id,
            'message_source_id' => $messageSourceId,
            'outgoing_echo' => $this->outgoingEcho,
            'sender_id' => $this->getSenderId(),
            'recipient_id' => $this->getRecipientId(),
            'inbox_id' => $this->inbox->id,
        ]);
        
        // Se contactInbox já foi definido, usa ele
        if ($this->contactInbox) {
            error_log('[INSTAGRAM MESSAGE BUILDER] ✅ ContactInbox já definido: ID=' . $this->contactInbox->id);
            $this->contact = $this->contactInbox->contact;
            return $this->contact;
        }
        
        // Busca contactInbox pelo source_id
        $this->contactInbox = $this->inbox->contactInboxes()
            ->where('source_id', $messageSourceId)
            ->first();

        if ($this->contactInbox) {
            $this->contact = $this->contactInbox->contact;
            error_log('[INSTAGRAM MESSAGE BUILDER] ✅ Contato encontrado: Contact ID=' . $this->contact->id . ', ContactInbox ID=' . $this->contactInbox->id);
            Log::info('[INSTAGRAM MESSAGE BUILDER] Contato encontrado', [
                'channel' => 'instagram',
                'account_id' => $this->inbox->account_id,
                'contact_id' => $this->contact->id,
                'contact_inbox_id' => $this->contactInbox->id,
            ]);
        } else {
            error_log('[INSTAGRAM MESSAGE BUILDER] ❌ ContactInbox NÃO encontrado para source_id: ' . $messageSourceId);
            error_log('[INSTAGRAM MESSAGE BUILDER] Source IDs disponíveis: ' . implode(', ', $this->inbox->contactInboxes()->pluck('source_id')->toArray()));
            Log::warning('[INSTAGRAM MESSAGE BUILDER] ContactInbox não encontrado', [
                'channel' => 'instagram',
                'account_id' => $this->inbox->account_id,
                'message_source_id' => $messageSourceId,
                'inbox_id' => $this->inbox->id,
                'available_source_ids' => $this->inbox->contactInboxes()->pluck('source_id')->toArray(),
            ]);
        }

        return $this->contact;
    }

    /**
     * Retorna a conversa
     * Similar ao Chatwoot: conversation
     * 
     * @return Conversation|null
     */
    protected function getConversation(): ?Conversation
    {
        if ($this->conversation) {
            return $this->conversation;
        }

        return $this->setConversationBasedOnInboxConfig();
    }

    /**
     * Define conversa baseado na configuração do inbox
     * Similar ao Chatwoot: set_conversation_based_on_inbox_config
     * 
     * @return Conversation|null
     */
    protected function setConversationBasedOnInboxConfig(): ?Conversation
    {
        $contact = $this->getContact();
        if (!$contact) {
            return null;
        }

        if ($this->inbox->lock_to_single_conversation) {
            // Busca última conversa ou cria nova
            $conversation = $this->findConversationScope()
                ->orderBy('created_at', 'desc')
                ->first();

            if ($conversation) {
                // Se estiver resolvida, reabre
                if ($conversation->status === Conversation::STATUS_RESOLVED) {
                    $conversation->update(['status' => Conversation::STATUS_OPEN]);
                    Log::info('[INSTAGRAM MESSAGE BUILDER] Conversa reaberta', ['conversation_id' => $conversation->id]);
                }
                
                $this->conversation = $conversation;
                return $conversation;
            }
        } else {
            // Comportamento padrão: Tenta encontrar conversa existente para reabrir ou continuar
            // Diferente do código anterior que criava nova se estivesse resolvida
            
            $lastConversation = $this->findConversationScope()
                ->orderBy('created_at', 'desc')
                ->first();

            if ($lastConversation) {
                // Se a conversa existir
                // 1. Se estiver aberta, usa ela
                // 2. Se estiver resolvida, reabre (comportamento padrão do Chatwoot para manter histórico)
                
                if ($lastConversation->status === Conversation::STATUS_RESOLVED) {
                    $lastConversation->update(['status' => Conversation::STATUS_OPEN]);
                    Log::info('[INSTAGRAM MESSAGE BUILDER] Conversa resolvida foi reaberta', ['conversation_id' => $lastConversation->id]);
                }
                
                $this->conversation = $lastConversation;
                return $lastConversation;
            }
        }

        // Cria nova conversa apenas se não encontrou nenhuma anterior
        return $this->buildConversation();
    }

    /**
     * Busca escopo de conversas
     * Similar ao Chatwoot: find_conversation_scope
     * 
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function findConversationScope()
    {
        $contact = $this->getContact();
        if (!$contact) {
            return Conversation::whereRaw('1 = 0'); // Query vazia
        }

        return Conversation::where($this->getConversationParams());
    }

    /**
     * Constrói nova conversa
     * Similar ao Chatwoot: build_conversation
     * 
     * @return Conversation
     */
    protected function buildConversation(): Conversation
    {
        error_log('[INSTAGRAM MESSAGE BUILDER] ========== BUILD CONVERSATION CHAMADO ==========');
        
        $contact = $this->getContact();
        if (!$contact) {
            error_log('[INSTAGRAM MESSAGE BUILDER] ❌ ERRO: Contact é obrigatório para criar conversa');
            error_log('[INSTAGRAM MESSAGE BUILDER] ContactInbox existe? ' . ($this->contactInbox ? 'SIM' : 'NÃO'));
            throw new \RuntimeException('Contact is required to build conversation');
        }

        error_log('[INSTAGRAM MESSAGE BUILDER] ✅ Contact encontrado: ID=' . $contact->id);
        
        $messageSourceId = $this->getMessageSourceId();
        error_log('[INSTAGRAM MESSAGE BUILDER] Message Source ID: ' . ($messageSourceId ?? 'NULL'));
        
        // Busca contact_inbox
        if (!$this->contactInbox) {
            error_log('[INSTAGRAM MESSAGE BUILDER] ContactInbox não definido, buscando...');
            $this->contactInbox = $contact->contactInboxes()
                ->where('source_id', $messageSourceId)
                ->where('inbox_id', $this->inbox->id)
                ->first();
            
            if (!$this->contactInbox) {
                error_log('[INSTAGRAM MESSAGE BUILDER] ❌ ERRO: ContactInbox não encontrado para criar conversa');
                error_log('[INSTAGRAM MESSAGE BUILDER] Source ID procurado: ' . $messageSourceId);
                error_log('[INSTAGRAM MESSAGE BUILDER] Inbox ID: ' . $this->inbox->id);
                throw new \RuntimeException("ContactInbox not found for source_id: {$messageSourceId}");
            }
        } else {
            error_log('[INSTAGRAM MESSAGE BUILDER] ✅ ContactInbox já definido: ID=' . $this->contactInbox->id);
        }

        // Calcula display_id
        $maxDisplayId = Conversation::where('account_id', $this->inbox->account_id)
            ->max('display_id') ?? 0;

        $conversationParams = array_merge(
            $this->getConversationParams(),
            [
                'contact_inbox_id' => $this->contactInbox->id,
                'display_id' => $maxDisplayId + 1,
                'status' => Conversation::STATUS_OPEN,
                'priority' => Conversation::PRIORITY_LOW,
                'last_activity_at' => now(),
                'additional_attributes' => $this->getAdditionalConversationAttributes(),
            ]
        );

        $this->conversation = Conversation::create($conversationParams);

        Log::info('[INSTAGRAM MESSAGE BUILDER] Conversa criada', [
            'conversation_id' => $this->conversation->id,
            'contact_inbox_id' => $this->contactInbox->id,
        ]);

        return $this->conversation;
    }

    /**
     * Parâmetros base para busca/criação de conversa
     * Similar ao Chatwoot: conversation_params
     * 
     * @return array
     */
    protected function getConversationParams(): array
    {
        $contact = $this->getContact();
        if (!$contact) {
            return [];
        }

        return [
            'account_id' => $this->inbox->account_id,
            'inbox_id' => $this->inbox->id,
            'contact_id' => $contact->id,
        ];
    }

    /**
     * Atributos adicionais da conversa
     * Similar ao Chatwoot: additional_conversation_attributes
     * 
     * @return array
     */
    protected function getAdditionalConversationAttributes(): array
    {
        return [];
    }

    /**
     * Parâmetros para criação da mensagem
     * Similar ao Chatwoot: message_params
     * 
     * @return array
     */
    protected function getMessageParams(): array
    {
        $conversation = $this->getConversation();
        $contact = $this->getContact();

        $params = [
            'account_id' => $conversation->account_id,
            'inbox_id' => $conversation->inbox_id,
            'conversation_id' => $conversation->id,
            'message_type' => $this->outgoingEcho ? Message::TYPE_OUTGOING : Message::TYPE_INCOMING,
            'content_type' => Message::CONTENT_TYPE_TEXT,
            'source_id' => $this->getMessageIdentifier(),
            'content' => $this->getMessageContent(),
            'status' => Message::STATUS_DELIVERED,
            'private' => '{}',
        ];

        // Adiciona sender apenas se não for echo
        // Usa relacionamento polimórfico (pode ser Contact ou User)
        if (!$this->outgoingEcho && $contact) {
            $params['sender'] = $contact; // Relacionamento polimórfico
        }

        // Adiciona content_attributes se houver reply
        $replyTo = $this->getMessageReplyAttributes();
        if ($replyTo) {
            $params['content_attributes'] = json_encode([
                'in_reply_to_external_id' => $replyTo,
            ]);
        }

        // Timestamp da mensagem
        if (isset($this->messaging['timestamp'])) {
            $timestamp = (int) ($this->messaging['timestamp'] / 1000);
            $params['created_at'] = \Carbon\Carbon::createFromTimestamp($timestamp);
        }

        return $params;
    }

    /**
     * ID do remetente
     * Similar ao Chatwoot: sender_id
     * 
     * @return string|null
     */
    protected function getSenderId(): ?string
    {
        return $this->messaging['sender']['id'] ?? null;
    }

    /**
     * ID do destinatário
     * Similar ao Chatwoot: recipient_id
     * 
     * @return string|null
     */
    protected function getRecipientId(): ?string
    {
        return $this->messaging['recipient']['id'] ?? null;
    }

    /**
     * Source ID da mensagem (remetente ou destinatário baseado no echo)
     * Similar ao Chatwoot: message_source_id
     * 
     * @return string|null
     */
    protected function getMessageSourceId(): ?string
    {
        return $this->outgoingEcho ? $this->getRecipientId() : $this->getSenderId();
    }

    /**
     * Dados da mensagem
     * Similar ao Chatwoot: message
     * 
     * @return array
     */
    protected function getMessage(): array
    {
        return $this->messaging['message'] ?? [];
    }

    /**
     * Identificador da mensagem (mid)
     * Similar ao Chatwoot: message_identifier
     * 
     * @return string|null
     */
    protected function getMessageIdentifier(): ?string
    {
        return $this->getMessage()['mid'] ?? null;
    }

    /**
     * Conteúdo da mensagem
     * Similar ao Chatwoot: message_content
     * 
     * @return string
     */
    protected function getMessageContent(): string
    {
        return $this->getMessage()['text'] ?? '';
    }

    /**
     * Atributos de reply da mensagem
     * Similar ao Chatwoot: message_reply_attributes
     * 
     * @return string|null
     */
    protected function getMessageReplyAttributes(): ?string
    {
        $message = $this->getMessage();
        if (!isset($message['reply_to']['mid'])) {
            return null;
        }

        return $message['reply_to']['mid'];
    }

    /**
     * Verifica se mensagem já existe
     * Similar ao Chatwoot: message_already_exists?
     * 
     * @return bool
     */
    protected function messageAlreadyExists(): bool
    {
        $sourceId = $this->getMessageIdentifier();
        if (!$sourceId) {
            return false;
        }

        // Verifica se já existe uma mensagem com este source_id
        // IMPORTANTE: Usa withoutGlobalScopes() para garantir que busca em todas as accounts
        // e adiciona verificação por inbox_id para evitar falsos positivos
        $exists = Message::withoutGlobalScopes()
            ->where('source_id', $sourceId)
            ->where('inbox_id', $this->inbox->id)
            ->exists();
        
        if ($exists) {
            Log::info('[INSTAGRAM MESSAGE BUILDER] Mensagem já existe (duplicada)', [
                'source_id' => $sourceId,
                'inbox_id' => $this->inbox->id,
            ]);
        }
        
        return $exists;
    }

    /**
     * Obtém anexos do webhook
     * Similar ao Chatwoot: attachments
     * 
     * @return array
     */
    protected function getAttachments(): array
    {
        $message = $this->getMessage();
        return $message['attachments'] ?? [];
    }

    /**
     * Processa anexos
     * Similar ao Chatwoot: process_attachment
     * 
     * @return void
     */
    protected function processAttachments(): void
    {
        $attachments = $this->getAttachments();

        if (empty($attachments)) {
            return;
        }

        Log::info('[INSTAGRAM MESSAGE BUILDER] Processando anexos', [
            'message_id' => $this->message->id,
            'attachments_count' => count($attachments),
        ]);

        foreach ($attachments as $attachment) {
            // Pula tipos não suportados
            if ($this->isUnsupportedFileType($attachment['type'] ?? null)) {
                Log::info('[INSTAGRAM MESSAGE BUILDER] Tipo de arquivo não suportado, pulando', [
                    'type' => $attachment['type'] ?? null,
                ]);
                continue;
            }

            $this->processAttachment($attachment);
        }
    }

    /**
     * Processa um anexo individual
     * Similar ao Chatwoot: process_attachment
     * 
     * @param array $attachment
     * @return void
     */
    protected function processAttachment(array $attachment): void
    {
        $attachmentParams = $this->getAttachmentParams($attachment);

        if (empty($attachmentParams)) {
            return;
        }

        try {
            // Cria attachment
            $attachmentModel = $this->message->attachments()->create($attachmentParams);

            // Se tem URL remota para download, baixa o arquivo
            $remoteFileUrl = $attachmentParams['remote_file_url'] ?? null;
            if ($remoteFileUrl && $this->shouldDownloadFile($attachment['type'] ?? null)) {
                $this->downloadAndAttachFile($attachmentModel, $remoteFileUrl);
            }

            Log::info('[INSTAGRAM MESSAGE BUILDER] Anexo criado', [
                'attachment_id' => $attachmentModel->id,
                'file_type' => $attachmentModel->file_type,
            ]);
        } catch (\Exception $e) {
            Log::error('[INSTAGRAM MESSAGE BUILDER] Erro ao processar anexo', [
                'attachment' => $attachment,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Obtém parâmetros para criar attachment
     * Similar ao Chatwoot: attachment_params
     * 
     * @param array $attachment
     * @return array
     */
    protected function getAttachmentParams(array $attachment): array
    {
        $fileType = $attachment['type'] ?? null;
        if (!$fileType) {
            return [];
        }

        $params = [
            'account_id' => $this->message->account_id,
            'file_type' => $this->mapFileType($fileType),
        ];

        // Tipos que precisam de URL
        if (in_array($fileType, ['image', 'file', 'audio', 'video', 'share', 'story_mention', 'ig_reel'])) {
            $params = array_merge($params, $this->getFileTypeParams($attachment));
        } elseif ($fileType === 'location') {
            $params = array_merge($params, $this->getLocationParams($attachment));
        } elseif ($fileType === 'fallback') {
            $params = array_merge($params, $this->getFallbackParams($attachment));
        }

        return $params;
    }

    /**
     * Parâmetros para tipos de arquivo (image, video, audio, file)
     * Similar ao Chatwoot: file_type_params
     * 
     * @param array $attachment
     * @return array
     */
    protected function getFileTypeParams(array $attachment): array
    {
        $url = $attachment['payload']['url'] ?? null;

        if (!$url) {
            return [];
        }

        return [
            'external_url' => $url,
            'remote_file_url' => $url, // Para download posterior
        ];
    }

    /**
     * Parâmetros para localização
     * Similar ao Chatwoot: location_params
     * 
     * @param array $attachment
     * @return array
     */
    protected function getLocationParams(array $attachment): array
    {
        $payload = $attachment['payload'] ?? [];
        $coordinates = $payload['coordinates'] ?? [];

        return [
            'coordinates_lat' => (float) ($coordinates['lat'] ?? 0.0),
            'coordinates_long' => (float) ($coordinates['long'] ?? 0.0),
            'fallback_title' => $payload['title'] ?? null,
            'external_url' => $payload['url'] ?? null,
        ];
    }

    /**
     * Parâmetros para fallback
     * Similar ao Chatwoot: fallback_params
     * 
     * @param array $attachment
     * @return array
     */
    protected function getFallbackParams(array $attachment): array
    {
        $payload = $attachment['payload'] ?? [];

        return [
            'fallback_title' => $payload['title'] ?? null,
            'external_url' => $payload['url'] ?? null,
        ];
    }

    /**
     * Mapeia tipo de arquivo do Instagram para tipo interno
     * Similar ao Chatwoot: file_type mapping
     * 
     * @param string $type
     * @return int
     */
    protected function mapFileType(string $type): int
    {
        return match ($type) {
            'image' => \App\Models\Attachment::FILE_TYPE_IMAGE,
            'audio' => \App\Models\Attachment::FILE_TYPE_AUDIO,
            'video' => \App\Models\Attachment::FILE_TYPE_VIDEO,
            'file' => \App\Models\Attachment::FILE_TYPE_FILE,
            'location' => \App\Models\Attachment::FILE_TYPE_LOCATION,
            'fallback' => \App\Models\Attachment::FILE_TYPE_FALLBACK,
            'share' => \App\Models\Attachment::FILE_TYPE_SHARE,
            'story_mention' => \App\Models\Attachment::FILE_TYPE_STORY_MENTION,
            'ig_reel' => \App\Models\Attachment::FILE_TYPE_IG_REEL,
            default => \App\Models\Attachment::FILE_TYPE_FILE,
        };
    }

    /**
     * Verifica se tipo de arquivo não é suportado
     * Similar ao Chatwoot: unsupported_file_type?
     * 
     * @param string|null $type
     * @return bool
     */
    protected function isUnsupportedFileType(?string $type): bool
    {
        if (!$type) {
            return true;
        }

        // Tipos não suportados: template, unsupported_type
        return in_array($type, ['template', 'unsupported_type']);
    }

    /**
     * Verifica se deve baixar arquivo
     * 
     * @param string|null $type
     * @return bool
     */
    protected function shouldDownloadFile(?string $type): bool
    {
        // Por enquanto, não baixamos arquivos automaticamente
        // Apenas usamos external_url (Instagram já fornece URL pública)
        // Isso pode ser alterado no futuro se necessário
        return false;
    }

    /**
     * Baixa e anexa arquivo
     * Similar ao Chatwoot: attach_file
     * 
     * @param \App\Models\Attachment $attachment
     * @param string $fileUrl
     * @return void
     */
    protected function downloadAndAttachFile(\App\Models\Attachment $attachment, string $fileUrl): void
    {
        // Por enquanto, não implementamos download automático
        // Instagram fornece URLs públicas, então podemos usar diretamente
        // Se necessário no futuro, podemos implementar download usando Http::get()
    }

    /**
     * Verifica se todos os arquivos são não suportados
     * Similar ao Chatwoot: all_unsupported_files?
     * 
     * @return bool
     */
    protected function allUnsupportedFiles(): bool
    {
        $attachments = $this->getAttachments();

        if (empty($attachments)) {
            return false;
        }

        // Se todos os anexos são não suportados
        foreach ($attachments as $attachment) {
            if (!$this->isUnsupportedFileType($attachment['type'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Trata erros
     * Similar ao Chatwoot: handle_error
     * 
     * @param \Exception $error
     * @return void
     */
    protected function handleError(\Exception $error): void
    {
        Log::error('[INSTAGRAM MESSAGE BUILDER] Erro ao construir mensagem', [
            'error' => $error->getMessage(),
            'trace' => $error->getTraceAsString(),
            'messaging' => $this->messaging,
        ]);
    }
}


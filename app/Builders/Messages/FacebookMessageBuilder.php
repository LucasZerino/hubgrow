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
 * FacebookMessageBuilder
 * 
 * Constrói mensagens do Facebook seguindo o padrão do Chatwoot.
 * Similar ao Chatwoot: Messages::Facebook::BaseMessageBuilder
 * 
 * @package App\Builders\Messages
 */
class FacebookMessageBuilder
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
     * Similar ao Instagram: setContactInbox
     * 
     * @param ContactInbox $contactInbox
     * @return self
     */
    public function setContactInbox(ContactInbox $contactInbox): self
    {
        // Garante que o relacionamento contact está carregado
        if (!$contactInbox->relationLoaded('contact')) {
            $contactInbox->load('contact');
        }
        
        $this->contactInbox = $contactInbox;
        $this->contact = $contactInbox->contact;
        
        Log::info('[FACEBOOK MESSAGE BUILDER] setContactInbox chamado', [
            'contact_inbox_id' => $this->contactInbox->id,
            'contact_id' => $this->contact?->id,
            'contact_loaded' => $this->contact !== null,
        ]);
        
        return $this;
    }


    /**
     * Construtor
     * 
     * @param array $messaging Dados do webhook do Facebook
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
            Log::info("[FACEBOOK MESSAGE BUILDER] Pular processamento de mensagem - reautorização necessária para inbox {$this->inbox->id}");
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
        
        // Verifica se mensagem já existe (duplicatas)
        if ($this->messageAlreadyExists()) {
            Log::info('[FACEBOOK MESSAGE BUILDER] Mensagem já existe, pulando criação', [
                'source_id' => $messageIdentifier,
            ]);
            return null;
        }

        // Se contactInbox não foi definido via setContactInbox(), busca pelo source_id
        // Similar ao Instagram: getContact()
        if (!$this->contactInbox) {
            Log::info('[FACEBOOK MESSAGE BUILDER] ContactInbox não foi definido via setContactInbox(), buscando pelo source_id');
            $messageSourceId = $this->getMessageSourceId();
            if ($messageSourceId) {
                $this->contactInbox = $this->inbox->contactInboxes()
                    ->where('source_id', $messageSourceId)
                    ->first();
                
                if ($this->contactInbox) {
                    $this->contact = $this->contactInbox->contact;
                    Log::info('[FACEBOOK MESSAGE BUILDER] ContactInbox encontrado', [
                        'contact_inbox_id' => $this->contactInbox->id,
                        'contact_id' => $this->contact->id ?? null,
                    ]);
                } else {
                    Log::warning('[FACEBOOK MESSAGE BUILDER] ContactInbox não encontrado pelo source_id', [
                        'source_id' => $messageSourceId,
                        'inbox_id' => $this->inbox->id,
                    ]);
                }
            } else {
                Log::warning('[FACEBOOK MESSAGE BUILDER] messageSourceId não encontrado');
            }
        } else {
            Log::info('[FACEBOOK MESSAGE BUILDER] ContactInbox já definido via setContactInbox()', [
                'contact_inbox_id' => $this->contactInbox->id,
                'contact_id' => $this->contactInbox->contact_id ?? null,
            ]);
        }

        if (!$this->contactInbox || !$this->contact) {
            Log::warning('[FACEBOOK MESSAGE BUILDER] ContactInbox ou Contact não encontrado, não é possível criar mensagem', [
                'has_contact_inbox' => $this->contactInbox !== null,
                'has_contact' => $this->contact !== null,
            ]);
            return null;
        }

        $conversation = $this->getConversation();
        if (!$conversation) {
            Log::warning('[FACEBOOK MESSAGE BUILDER] Conversa não encontrada, não é possível criar mensagem');
            return null;
        }

        // Determina content_type baseado no que a mensagem tem
        $hasContent = $this->getContent() !== null;
        $hasAttachments = !empty($this->messaging['message']['attachments'] ?? []);
        
        $contentType = Message::CONTENT_TYPE_TEXT;
        if (!$hasContent && $hasAttachments) {
            // Se não tem texto mas tem attachments, determina o tipo pelo primeiro attachment
            $firstAttachment = $this->messaging['message']['attachments'][0] ?? [];
            $attachmentType = $firstAttachment['type'] ?? 'file';
            
            // Mapeia tipo do Facebook para content_type do Chatwoot (strings)
            $contentTypeMap = [
                'image' => Message::CONTENT_TYPE_IMAGE,
                'video' => Message::CONTENT_TYPE_VIDEO,
                'audio' => Message::CONTENT_TYPE_AUDIO,
                'file' => Message::CONTENT_TYPE_FILE,
                'location' => Message::CONTENT_TYPE_LOCATION,
            ];
            $contentType = $contentTypeMap[$attachmentType] ?? Message::CONTENT_TYPE_FILE;
        }

        // Cria mensagem
        // IMPORTANTE: Usa relacionamento polimórfico para sender (pode ser Contact ou User)
        // Seguindo o padrão do Chatwoot original
        $this->message = $conversation->messages()->create([
            'account_id' => $this->inbox->account_id,
            'inbox_id' => $this->inbox->id,
            'message_type' => $this->outgoingEcho ? Message::TYPE_OUTGOING : Message::TYPE_INCOMING,
            'content' => $this->getContent(), // Pode ser null se só tiver attachments
            'sender' => $this->outgoingEcho ? null : $this->contactInbox->contact, // Relacionamento polimórfico
            'source_id' => $messageIdentifier,
            'external_source_id' => $messageIdentifier,
            'content_type' => $contentType,
            'content_attributes' => [],
            'additional_attributes' => [],
        ]);

        // Processa anexos
        $this->processAttachments();

        Log::info('[FACEBOOK MESSAGE BUILDER] Mensagem criada', [
            'message_id' => $this->message->id,
            'conversation_id' => $conversation->id,
        ]);

        return $this->message;
    }


    /**
     * Retorna identificador da mensagem (mid)
     * 
     * @return string|null
     */
    protected function getMessageIdentifier(): ?string
    {
        return $this->messaging['message']['mid'] ?? null;
    }

    /**
     * Verifica se mensagem já existe
     * 
     * @return bool
     */
    protected function messageAlreadyExists(): bool
    {
        $messageIdentifier = $this->getMessageIdentifier();
        if (!$messageIdentifier) {
            return false;
        }

        return Message::where('inbox_id', $this->inbox->id)
            ->where('source_id', $messageIdentifier)
            ->exists();
    }


    /**
     * Retorna source_id do contato (sender_id ou recipient_id dependendo se é echo)
     * 
     * @return string|null
     */
    protected function getMessageSourceId(): ?string
    {
        if ($this->outgoingEcho) {
            // Echo: recipient é o contato
            return $this->messaging['recipient']['id'] ?? null;
        }
        
        // Mensagem normal: sender é o contato
        return $this->messaging['sender']['id'] ?? null;
    }

    /**
     * Retorna a conversa
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
     * 
     * @return Conversation|null
     */
    protected function setConversationBasedOnInboxConfig(): ?Conversation
    {
        if (!$this->contactInbox || !$this->contactInbox->contact) {
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
                    Log::info('[FACEBOOK MESSAGE BUILDER] Conversa reaberta', ['conversation_id' => $conversation->id]);
                }
                
                $this->conversation = $conversation;
                return $conversation;
            }
        } else {
            // Comportamento padrão: Tenta encontrar conversa existente para reabrir ou continuar
            
            $lastConversation = $this->findConversationScope()
                ->orderBy('created_at', 'desc')
                ->first();

            if ($lastConversation) {
                // Se a conversa existir
                // 1. Se estiver aberta, usa ela
                // 2. Se estiver resolvida, reabre (comportamento padrão do Chatwoot para manter histórico)
                
                if ($lastConversation->status === Conversation::STATUS_RESOLVED) {
                    $lastConversation->update(['status' => Conversation::STATUS_OPEN]);
                    Log::info('[FACEBOOK MESSAGE BUILDER] Conversa resolvida foi reaberta', ['conversation_id' => $lastConversation->id]);
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
     * 
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function findConversationScope()
    {
        if (!$this->contactInbox || !$this->contactInbox->contact) {
            return Conversation::whereRaw('1 = 0'); // Query vazia
        }

        return Conversation::where($this->getConversationParams());
    }

    /**
     * Retorna parâmetros para buscar conversa
     * 
     * @return array
     */
    protected function getConversationParams(): array
    {
        if (!$this->contactInbox || !$this->contactInbox->contact) {
            return [];
        }

        return [
            'account_id' => $this->inbox->account_id,
            'inbox_id' => $this->inbox->id,
            'contact_id' => $this->contactInbox->contact_id,
        ];
    }

    /**
     * Constrói nova conversa
     * 
     * @return Conversation
     */
    /**
     * Constrói nova conversa
     * Seguindo EXATAMENTE o padrão do Chatwoot
     * 
     * @return Conversation
     */
    protected function buildConversation(): Conversation
    {
        if (!$this->contactInbox || !$this->contactInbox->contact) {
            throw new \RuntimeException('ContactInbox and Contact are required to build conversation');
        }

        // Seguindo EXATAMENTE o padrão do Chatwoot:
        // conversation_params retorna apenas: account_id, inbox_id, contact_id
        // contact_inbox_id é adicionado via merge DIRETO no create()
        $baseParams = $this->getConversationParams();
        
        // Calcula display_id (Chatwoot usa trigger do banco, mas precisamos calcular aqui)
        $maxDisplayId = Conversation::where('account_id', $this->inbox->account_id)
            ->max('display_id') ?? 0;
        
        // EXATAMENTE como o Chatwoot faz:
        // Conversation.create!(conversation_params.merge(contact_inbox_id: @contact_inbox.id))
        // 
        // No Chatwoot, eles fazem merge direto no create!()
        // Vamos fazer exatamente a mesma coisa
        $contactInboxId = $this->contactInbox->id;
        
        if (!$contactInboxId) {
            throw new \RuntimeException('contactInbox->id não pode ser null ou zero');
        }
        
        // Cria o array de parâmetros exatamente como o Chatwoot
        // Conversation.create!(conversation_params.merge(contact_inbox_id: @contact_inbox.id))
        $params = array_merge(
            $baseParams,
            [
                'contact_inbox_id' => $contactInboxId,
                'display_id' => $maxDisplayId + 1,
                'status' => Conversation::STATUS_OPEN,
                'additional_attributes' => [],
            ]
        );
        
        // Log para debug - verifica se contact_inbox_id está presente
        Log::info('[FACEBOOK MESSAGE BUILDER] Parâmetros antes de criar', [
            'params_keys' => array_keys($params),
            'contact_inbox_id' => $params['contact_inbox_id'] ?? 'NOT SET',
            'contact_inbox_id_type' => gettype($params['contact_inbox_id'] ?? null),
            'contact_inbox_id_value' => $params['contact_inbox_id'],
            'contact_inbox_object_id' => $this->contactInbox->id,
            'all_params' => $params,
            'base_params' => $baseParams,
        ]);
        
        // Validação crítica: garante que contact_inbox_id está presente
        if (!isset($params['contact_inbox_id']) || $params['contact_inbox_id'] === null) {
            Log::error('[FACEBOOK MESSAGE BUILDER] contact_inbox_id NÃO está presente!', [
                'params' => $params,
                'base_params' => $baseParams,
                'contact_inbox_id' => $this->contactInbox->id ?? 'NULL',
            ]);
            throw new \RuntimeException('contact_inbox_id não está presente nos parâmetros');
        }
        
        // IMPORTANTE: Verifica se o campo está no fillable do modelo
        $fillable = (new Conversation())->getFillable();
        Log::info('[FACEBOOK MESSAGE BUILDER] Campos fillable do Conversation', [
            'fillable' => $fillable,
            'contact_inbox_id_in_fillable' => in_array('contact_inbox_id', $fillable),
        ]);
        
        // Cria usando create() diretamente, como o InstagramMessageBuilder faz
        // IMPORTANTE: O Laravel pode remover campos de relacionamento belongsTo do array
        // Por isso, vamos criar a conversa e depois definir o relacionamento explicitamente
        try {
            // Garante que contact_inbox_id está presente e não é null
            if (!isset($params['contact_inbox_id']) || $params['contact_inbox_id'] === null) {
                throw new \RuntimeException('contact_inbox_id é obrigatório mas está null');
            }
            
            // Salva o contact_inbox_id antes de criar (pode ser removido pelo Laravel)
            $contactInboxId = $params['contact_inbox_id'];
            
            // Cria a conversa
            $this->conversation = Conversation::create($params);
            
            // Se o contact_inbox_id não foi salvo, define explicitamente
            if ($this->conversation->contact_inbox_id !== $contactInboxId) {
                Log::warning('[FACEBOOK MESSAGE BUILDER] contact_inbox_id não foi salvo, definindo explicitamente', [
                    'expected' => $contactInboxId,
                    'actual' => $this->conversation->contact_inbox_id,
                ]);
                $this->conversation->contact_inbox_id = $contactInboxId;
                $this->conversation->save();
            }
            
            Log::info('[FACEBOOK MESSAGE BUILDER] Conversation::create() executado com sucesso', [
                'conversation_id' => $this->conversation->id,
                'contact_inbox_id' => $this->conversation->contact_inbox_id,
                'contact_inbox_id_from_params' => $contactInboxId,
            ]);
        } catch (\Exception $e) {
            Log::error('[FACEBOOK MESSAGE BUILDER] Erro ao executar Conversation::create()', [
                'error' => $e->getMessage(),
                'params' => $params,
                'params_contact_inbox_id' => $params['contact_inbox_id'] ?? 'NOT SET',
                'params_keys' => array_keys($params),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
        
        Log::info('[FACEBOOK MESSAGE BUILDER] Conversa criada com sucesso', [
            'conversation_id' => $this->conversation->id,
            'contact_inbox_id' => $this->conversation->contact_inbox_id,
            'contact_inbox_id_from_params' => $params['contact_inbox_id'],
        ]);

        return $this->conversation;
    }

    /**
     * Retorna conteúdo da mensagem
     * 
     * @return string|null
     */
    protected function getContent(): ?string
    {
        return $this->messaging['message']['text'] ?? null;
    }

    /**
     * Processa anexos
     * 
     * @return void
     */
    protected function processAttachments(): void
    {
        if (!$this->message) {
            return;
        }

        $attachments = $this->messaging['message']['attachments'] ?? [];
        if (empty($attachments)) {
            return;
        }

        foreach ($attachments as $attachment) {
            $this->processAttachment($attachment);
        }
    }

    /**
     * Processa um anexo
     * 
     * @param array $attachment
     * @return void
     */
    protected function processAttachment(array $attachment): void
    {
        $type = $attachment['type'] ?? 'file';
        $payload = $attachment['payload'] ?? [];
        $url = $payload['url'] ?? null;

        if (!$url) {
            Log::warning('[FACEBOOK MESSAGE BUILDER] Anexo sem URL', [
                'attachment' => $attachment,
            ]);
            return;
        }

        // Mapeia tipo do Facebook para tipo do Chatwoot
        $fileType = $this->mapAttachmentType($type);

        // Cria attachment no banco
        $attachmentModel = $this->message->attachments()->create([
            'account_id' => $this->message->account_id,
            'file_type' => $fileType,
            'external_url' => $url,
        ]);

        // Baixa e salva o arquivo
        try {
            $this->downloadAndSaveAttachment($attachmentModel, $url);
        } catch (\Exception $e) {
            Log::error('[FACEBOOK MESSAGE BUILDER] Erro ao baixar anexo', [
                'attachment_id' => $attachmentModel->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mapeia tipo de anexo do Facebook para tipo do Chatwoot (constantes numéricas)
     * 
     * @param string $facebookType
     * @return int
     */
    protected function mapAttachmentType(string $facebookType): int
    {
        $mapping = [
            'image' => \App\Models\Attachment::FILE_TYPE_IMAGE,
            'video' => \App\Models\Attachment::FILE_TYPE_VIDEO,
            'audio' => \App\Models\Attachment::FILE_TYPE_AUDIO,
            'file' => \App\Models\Attachment::FILE_TYPE_FILE,
            'location' => \App\Models\Attachment::FILE_TYPE_LOCATION,
        ];

        return $mapping[$facebookType] ?? \App\Models\Attachment::FILE_TYPE_FILE;
    }

    /**
     * Baixa e salva anexo
     * 
     * @param \App\Models\Attachment $attachment
     * @param string $url
     * @return void
     */
    protected function downloadAndSaveAttachment(\App\Models\Attachment $attachment, string $url): void
    {
        // Baixa o arquivo
        $response = \Illuminate\Support\Facades\Http::timeout(30)->get($url);
        
        if (!$response->successful()) {
            throw new \Exception("Failed to download attachment: HTTP {$response->status()}");
        }

        // Determina nome do arquivo
        $filename = $this->getFilenameFromUrl($url);
        $contentType = $response->header('Content-Type') ?? 'application/octet-stream';
        
        // Salva o arquivo no storage
        $filePath = 'attachments/' . $attachment->id . '/' . $filename;
        \Illuminate\Support\Facades\Storage::put($filePath, $response->body());
        
        // Atualiza attachment com informações do arquivo
        $attachment->update([
            'file_path' => $filePath,
            'file_name' => $filename,
            'mime_type' => $contentType,
            'file_size' => strlen($response->body()),
        ]);
    }

    /**
     * Extrai nome do arquivo da URL
     * 
     * @param string $url
     * @return string
     */
    protected function getFilenameFromUrl(string $url): string
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';
        $filename = basename($path);
        
        // Se não tiver extensão, tenta extrair do Content-Disposition header
        if (empty(pathinfo($filename, PATHINFO_EXTENSION))) {
            $filename = 'attachment_' . time() . '.bin';
        }
        
        return $filename;
    }

    /**
     * Trata erros
     * 
     * @param \Exception $e
     * @return void
     */
    protected function handleError(\Exception $e): void
    {
        Log::error('[FACEBOOK MESSAGE BUILDER] Erro ao processar mensagem', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'messaging' => $this->messaging,
        ]);
    }
}


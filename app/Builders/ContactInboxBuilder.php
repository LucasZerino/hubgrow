<?php

namespace App\Builders;

use App\Models\Contact;
use App\Models\ContactInbox;
use App\Models\Inbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ContactInboxBuilder
 * 
 * Cria contact_inbox com atributos especificados.
 * Se o contact_inbox já existe, retorna o existente.
 * Similar ao Chatwoot: ContactInboxBuilder
 * 
 * @package App\Builders
 */
class ContactInboxBuilder
{
    protected Contact $contact;
    protected Inbox $inbox;
    protected ?string $sourceId;
    protected bool $hmacVerified;

    /**
     * Construtor
     * 
     * @param Contact $contact
     * @param Inbox $inbox
     * @param string|null $sourceId
     * @param bool $hmacVerified
     */
    public function __construct(
        Contact $contact,
        Inbox $inbox,
        ?string $sourceId = null,
        bool $hmacVerified = false
    ) {
        $this->contact = $contact;
        $this->inbox = $inbox;
        $this->sourceId = $sourceId;
        $this->hmacVerified = $hmacVerified;
    }

    /**
     * Executa a criação do contact_inbox
     * 
     * @return ContactInbox
     */
    public function perform(): ContactInbox
    {
        // Gera source_id se não fornecido
        if (!$this->sourceId) {
            $this->sourceId = $this->generateSourceId();
        }

        if (!$this->sourceId) {
            throw new \InvalidArgumentException('Source ID is required for creating contact inbox');
        }

        return $this->createContactInbox();
    }

    /**
     * Cria o contact_inbox
     * Similar ao Chatwoot: create_contact_inbox
     * 
     * @return ContactInbox
     */
    protected function createContactInbox(): ContactInbox
    {
        $attrs = [
            'contact_id' => $this->contact->id,
            'inbox_id' => $this->inbox->id,
            'source_id' => $this->sourceId,
        ];

        try {
            return ContactInbox::firstOrCreate($attrs, [
                'account_id' => $this->inbox->account_id,
                'hmac_verified' => $this->hmacVerified,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Race condition: outro processo criou enquanto tentávamos criar
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE')) {
                Log::warning('[CONTACT_INBOX_BUILDER] Race condition detectada, buscando contact_inbox existente', [
                    'attrs' => $attrs,
                ]);
                return ContactInbox::where($attrs)->firstOrFail();
            }
            throw $e;
        }
    }

    /**
     * Gera source_id baseado no tipo de canal
     * Similar ao Chatwoot: generate_source_id
     * 
     * @return string|null
     */
    protected function generateSourceId(): ?string
    {
        $channelType = $this->inbox->channel_type;

        // Para Instagram, não geramos automaticamente - deve ser fornecido
        // Outros canais podem ter lógica específica aqui
        
        return null;
    }
}


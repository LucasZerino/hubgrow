<?php

namespace App\Builders;

use App\Models\Contact;
use App\Models\ContactInbox;
use App\Models\Inbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ContactInboxWithContactBuilder
 * 
 * Cria contato e contact_inbox com atributos especificados.
 * Se um contato identificado existir, será retornado.
 * Para lógica de contact_inbox, usa o ContactInboxBuilder.
 * Similar ao Chatwoot: ContactInboxWithContactBuilder
 * 
 * @package App\Builders
 */
class ContactInboxWithContactBuilder
{
    protected Inbox $inbox;
    protected array $contactAttributes;
    protected ?string $sourceId;
    protected bool $hmacVerified;

    /**
     * Construtor
     * 
     * @param Inbox $inbox
     * @param array $contactAttributes Atributos do contato (name, email, phone_number, identifier, etc)
     * @param string|null $sourceId Instagram ID ou outro source_id
     * @param bool $hmacVerified
     */
    public function __construct(
        Inbox $inbox,
        array $contactAttributes,
        ?string $sourceId = null,
        bool $hmacVerified = false
    ) {
        $this->inbox = $inbox;
        $this->contactAttributes = $contactAttributes;
        $this->sourceId = $sourceId;
        $this->hmacVerified = $hmacVerified;
    }

    /**
     * Executa a criação do contato e contact_inbox
     * Similar ao Chatwoot: find_or_create_contact_and_contact_inbox
     * 
     * @return ContactInbox
     */
    public function perform(): ContactInbox
    {
        try {
            return $this->findOrCreateContactAndContactInbox();
        } catch (\Illuminate\Database\QueryException $e) {
            // Race condition: outro processo criou enquanto tentávamos criar
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE')) {
                Log::warning('[CONTACT_INBOX_WITH_CONTACT_BUILDER] Race condition detectada, tentando novamente', [
                    'source_id' => $this->sourceId,
                ]);
                return $this->findOrCreateContactAndContactInbox();
            }
            throw $e;
        }
    }

    /**
     * Busca ou cria contato e contact_inbox
     * 
     * @return ContactInbox
     */
    protected function findOrCreateContactAndContactInbox(): ContactInbox
    {
        // Se source_id está presente, busca contact_inbox existente
        if ($this->sourceId) {
            $contactInbox = $this->inbox->contactInboxes()
                ->where('source_id', $this->sourceId)
                ->first();

            if ($contactInbox) {
                Log::info('[CONTACT_INBOX_WITH_CONTACT_BUILDER] ContactInbox existente encontrado', [
                    'contact_inbox_id' => $contactInbox->id,
                    'source_id' => $this->sourceId,
                ]);
                return $contactInbox;
            }
        }

        // Cria em transação
        return DB::transaction(function () {
            return $this->buildContactWithContactInbox();
        });
    }

    /**
     * Constrói contato e contact_inbox
     * Similar ao Chatwoot: build_contact_with_contact_inbox
     * 
     * @return ContactInbox
     */
    protected function buildContactWithContactInbox(): ContactInbox
    {
        // Busca ou cria contato
        $contact = $this->findContact();
        
        if ($contact) {
            // Se encontrou contato existente, atualiza os identificadores se necessário
            $this->updateContactIdentifiers($contact);
        } else {
            // Cria novo contato
            $contact = $this->createContact();
        }

        // Cria contact_inbox usando ContactInboxBuilder
        $builder = new ContactInboxBuilder(
            $contact,
            $this->inbox,
            $this->sourceId,
            $this->hmacVerified
        );

        return $builder->perform();
    }

    /**
     * Atualiza identificadores do contato se necessário
     * Permite que um contato tenha tanto identifier_facebook quanto identifier_instagram
     * 
     * @param Contact $contact
     * @return void
     */
    protected function updateContactIdentifiers(Contact $contact): void
    {
        $updates = [];
        
        // Atualiza identifier_facebook se fornecido e não estiver definido
        if (!empty($this->contactAttributes['identifier_facebook']) && empty($contact->identifier_facebook)) {
            $updates['identifier_facebook'] = $this->contactAttributes['identifier_facebook'];
            Log::info('[CONTACT_INBOX_WITH_CONTACT_BUILDER] Atualizando identifier_facebook do contato', [
                'contact_id' => $contact->id,
                'identifier_facebook' => $this->contactAttributes['identifier_facebook'],
            ]);
        }
        
        // Atualiza identifier_instagram se fornecido e não estiver definido
        if (!empty($this->contactAttributes['identifier_instagram']) && empty($contact->identifier_instagram)) {
            $updates['identifier_instagram'] = $this->contactAttributes['identifier_instagram'];
            Log::info('[CONTACT_INBOX_WITH_CONTACT_BUILDER] Atualizando identifier_instagram do contato', [
                'contact_id' => $contact->id,
                'identifier_instagram' => $this->contactAttributes['identifier_instagram'],
            ]);
        }
        
        if (!empty($updates)) {
            $contact->update($updates);
        }
    }

    /**
     * Busca contato existente
     * Similar ao Chatwoot: find_contact
     * 
     * @return Contact|null
     */
    protected function findContact(): ?Contact
    {
        $accountId = $this->inbox->account_id;

        // 1. Busca por identifier_facebook (prioridade para Facebook)
        if (!empty($this->contactAttributes['identifier_facebook'])) {
            $contact = Contact::where('account_id', $accountId)
                ->where('identifier_facebook', $this->contactAttributes['identifier_facebook'])
                ->first();

            if ($contact) {
                Log::info('[CONTACT_INBOX_WITH_CONTACT_BUILDER] Contato encontrado por identifier_facebook', [
                    'contact_id' => $contact->id,
                    'identifier_facebook' => $this->contactAttributes['identifier_facebook'],
                ]);
                return $contact;
            }
        }

        // 2. Busca por identifier_instagram (prioridade para Instagram)
        if (!empty($this->contactAttributes['identifier_instagram'])) {
            $contact = Contact::where('account_id', $accountId)
                ->where('identifier_instagram', $this->contactAttributes['identifier_instagram'])
                ->first();

            if ($contact) {
                Log::info('[CONTACT_INBOX_WITH_CONTACT_BUILDER] Contato encontrado por identifier_instagram', [
                    'contact_id' => $contact->id,
                    'identifier_instagram' => $this->contactAttributes['identifier_instagram'],
                ]);
                return $contact;
            }
        }

        // 4. Busca por email
        if (!empty($this->contactAttributes['email'])) {
            $contact = Contact::where('account_id', $accountId)
                ->where('email', $this->contactAttributes['email'])
                ->first();

            if ($contact) {
                Log::info('[CONTACT_INBOX_WITH_CONTACT_BUILDER] Contato encontrado por email', [
                    'contact_id' => $contact->id,
                ]);
                return $contact;
            }
        }

        // 5. Busca por phone_number
        if (!empty($this->contactAttributes['phone_number'])) {
            $contact = Contact::where('account_id', $accountId)
                ->where('phone_number', $this->contactAttributes['phone_number'])
                ->first();

            if ($contact) {
                Log::info('[CONTACT_INBOX_WITH_CONTACT_BUILDER] Contato encontrado por phone_number', [
                    'contact_id' => $contact->id,
                ]);
                return $contact;
            }
        }

        // 6. Busca por instagram_source_id em outros canais (se for canal Instagram)
        if ($this->isInstagramChannel() && $this->sourceId) {
            $contact = $this->findContactByInstagramSourceId($this->sourceId);
            if ($contact) {
                return $contact;
            }
        }

        // 7. Busca por facebook_source_id em outros canais (se for canal Facebook)
        if ($this->isFacebookChannel() && $this->sourceId) {
            $contact = $this->findContactByFacebookSourceId($this->sourceId);
            if ($contact) {
                return $contact;
            }
        }

        return null;
    }

    /**
     * Cria novo contato
     * Similar ao Chatwoot: create_contact
     * 
     * @return Contact
     */
    protected function createContact(): Contact
    {
        $name = $this->contactAttributes['name'] 
            ?? $this->contactAttributes['username'] 
            ?? 'Unknown Contact';

        Log::info('[CONTACT_INBOX_WITH_CONTACT_BUILDER] Criando novo contato', [
            'name' => $name,
        ]);

        return Contact::create([
            'account_id' => $this->inbox->account_id,
            'name' => $name,
            'email' => $this->contactAttributes['email'] ?? null,
            'phone_number' => $this->contactAttributes['phone_number'] ?? null,
            'identifier_facebook' => $this->contactAttributes['identifier_facebook'] ?? null,
            'identifier_instagram' => $this->contactAttributes['identifier_instagram'] ?? null,
            'additional_attributes' => $this->contactAttributes['additional_attributes'] ?? [],
            'custom_attributes' => $this->contactAttributes['custom_attributes'] ?? [],
        ]);
    }

    /**
     * Busca contato por Instagram source_id
     * Similar ao Chatwoot: find_contact_by_instagram_source_id
     * 
     * Pode haver contact_inboxes existentes criados via Channel::FacebookPage
     * com o mesmo Instagram source_id. Novas interações do Instagram devem criar
     * contact_inboxes novos, mas reutilizar contatos se encontrados em canais Facebook.
     * 
     * @param string $instagramId
     * @return Contact|null
     */
    protected function findContactByInstagramSourceId(string $instagramId): ?Contact
    {
        if (!$instagramId) {
            return null;
        }

        // Busca ContactInbox de FacebookPage com mesmo source_id
        $existingContactInbox = ContactInbox::join('inboxes', 'contact_inboxes.inbox_id', '=', 'inboxes.id')
            ->where('contact_inboxes.source_id', $instagramId)
            ->where('inboxes.account_id', $this->inbox->account_id)
            ->where('inboxes.channel_type', '!=', $this->inbox->channel_type)
            ->select('contact_inboxes.*')
            ->first();

        if ($existingContactInbox && $existingContactInbox->contact) {
            Log::info('[CONTACT_INBOX_WITH_CONTACT_BUILDER] Contato encontrado por instagram_source_id em outro canal', [
                'contact_id' => $existingContactInbox->contact->id,
                'channel_type' => $existingContactInbox->inbox->channel_type ?? 'unknown',
            ]);
            return $existingContactInbox->contact;
        }

        return null;
    }

    /**
     * Verifica se é canal Instagram
     * 
     * @return bool
     */
    protected function isInstagramChannel(): bool
    {
        return $this->inbox->channel_type === \App\Models\Channel\InstagramChannel::class;
    }

    /**
     * Verifica se é canal Facebook
     * 
     * @return bool
     */
    protected function isFacebookChannel(): bool
    {
        return $this->inbox->channel_type === \App\Models\Channel\FacebookChannel::class;
    }

    /**
     * Busca contato por Facebook source_id
     * Similar ao findContactByInstagramSourceId, mas para Facebook
     * 
     * @param string $facebookId
     * @return Contact|null
     */
    protected function findContactByFacebookSourceId(string $facebookId): ?Contact
    {
        if (!$facebookId) {
            return null;
        }

        // Busca ContactInbox de InstagramChannel com mesmo source_id
        $existingContactInbox = ContactInbox::join('inboxes', 'contact_inboxes.inbox_id', '=', 'inboxes.id')
            ->where('contact_inboxes.source_id', $facebookId)
            ->where('inboxes.account_id', $this->inbox->account_id)
            ->where('inboxes.channel_type', '!=', $this->inbox->channel_type)
            ->select('contact_inboxes.*')
            ->first();

        if ($existingContactInbox && $existingContactInbox->contact) {
            Log::info('[CONTACT_INBOX_WITH_CONTACT_BUILDER] Contato encontrado por facebook_source_id em outro canal', [
                'contact_id' => $existingContactInbox->contact->id,
                'channel_type' => $existingContactInbox->inbox->channel_type ?? 'unknown',
            ]);
            return $existingContactInbox->contact;
        }

        return null;
    }

    /**
     * Retorna a account
     * 
     * @return \App\Models\Account
     */
    protected function getAccount(): \App\Models\Account
    {
        return $this->inbox->account;
    }
}


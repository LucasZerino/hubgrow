<?php

namespace App\Http\Controllers\Api\V1\Widget;

use App\Http\Controllers\Controller;
use App\Models\Channel\WebWidgetChannel;
use App\Models\Contact;
use App\Models\ContactInbox;
use App\Models\Conversation;
use App\Models\Inbox;
use Illuminate\Http\Request;

/**
 * Controller BaseController
 * 
 * Controller base para APIs públicas do widget.
 * Contém lógica comum para autenticação e busca de recursos.
 * 
 * @package App\Http\Controllers\Api\V1\Widget
 */
class BaseController extends Controller
{
    /**
     * Retorna o canal WebWidget do request
     * 
     * @param Request $request
     * @return WebWidgetChannel
     */
    protected function getWebWidgetChannel(Request $request): WebWidgetChannel
    {
        return $request->get('web_widget_channel');
    }

    /**
     * Retorna o inbox do request
     * 
     * @param Request $request
     * @return Inbox
     */
    protected function getInbox(Request $request): Inbox
    {
        return $request->get('inbox');
    }

    /**
     * Busca ou cria contato baseado nos parâmetros
     * 
     * @param Request $request
     * @return Contact
     */
    protected function findOrCreateContact(Request $request): Contact
    {
        $inbox = $this->getInbox($request);
        $contactData = $request->input('contact', []);

        // Busca por email, phone ou identifier
        $contact = null;
        if (!empty($contactData['email'])) {
            $contact = Contact::where('account_id', $inbox->account_id)
                ->where('email', $contactData['email'])
                ->first();
        } elseif (!empty($contactData['phone_number'])) {
            $contact = Contact::where('account_id', $inbox->account_id)
                ->where('phone_number', $contactData['phone_number'])
                ->first();
        } elseif (!empty($contactData['identifier'])) {
            $contact = Contact::where('account_id', $inbox->account_id)
                ->where('identifier', $contactData['identifier'])
                ->first();
        }

        // Cria contato se não encontrado
        if (!$contact) {
            $contact = Contact::create([
                'account_id' => $inbox->account_id,
                'name' => $contactData['name'] ?? null,
                'email' => $contactData['email'] ?? null,
                'phone_number' => $contactData['phone_number'] ?? null,
                'identifier' => $contactData['identifier'] ?? null,
            ]);
        } else {
            // Atualiza dados se fornecidos
            $updateData = [];
            if (!empty($contactData['name']) && empty($contact->name)) {
                $updateData['name'] = $contactData['name'];
            }
            if (!empty($contactData['email']) && empty($contact->email)) {
                $updateData['email'] = $contactData['email'];
            }
            if (!empty($contactData['phone_number']) && empty($contact->phone_number)) {
                $updateData['phone_number'] = $contactData['phone_number'];
            }
            if (!empty($updateData)) {
                $contact->update($updateData);
            }
        }

        // Garante que existe ContactInbox
        $contactInbox = ContactInbox::firstOrCreate([
            'contact_id' => $contact->id,
            'inbox_id' => $inbox->id,
        ], [
            'account_id' => $inbox->account_id,
            'source_id' => $contact->id . '_' . $inbox->id, // ID único para webwidget
        ]);

        return $contact;
    }

    /**
     * Busca conversa do contato no inbox
     * 
     * @param Request $request
     * @param Contact $contact
     * @return Conversation|null
     */
    protected function findConversation(Request $request, Contact $contact): ?Conversation
    {
        $inbox = $this->getInbox($request);
        $contactInbox = ContactInbox::where('contact_id', $contact->id)
            ->where('inbox_id', $inbox->id)
            ->first();

        if (!$contactInbox) {
            return null;
        }

        return Conversation::where('contact_inbox_id', $contactInbox->id)
            ->where('inbox_id', $inbox->id)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Cria nova conversa
     * 
     * @param Request $request
     * @param Contact $contact
     * @return Conversation
     */
    protected function createConversation(Request $request, Contact $contact): Conversation
    {
        $inbox = $this->getInbox($request);
        $contactInbox = ContactInbox::firstOrCreate([
            'contact_id' => $contact->id,
            'inbox_id' => $inbox->id,
        ], [
            'account_id' => $inbox->account_id,
            'source_id' => $contact->id . '_' . $inbox->id,
        ]);

        $messageData = $request->input('message', []);
        $timestamp = $messageData['timestamp'] ?? now()->timestamp;

        return Conversation::create([
            'account_id' => $inbox->account_id,
            'inbox_id' => $inbox->id,
            'contact_id' => $contact->id,
            'contact_inbox_id' => $contactInbox->id,
            'status' => 'open',
            'additional_attributes' => [
                'browser_language' => $request->header('Accept-Language', 'en'),
                'initiated_at' => ['timestamp' => $timestamp],
                'referer' => $messageData['referer_url'] ?? null,
            ],
            'custom_attributes' => $request->input('custom_attributes', []),
        ]);
    }
}

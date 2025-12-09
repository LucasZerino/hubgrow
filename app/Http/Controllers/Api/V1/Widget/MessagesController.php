<?php

namespace App\Http\Controllers\Api\V1\Widget;

use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller MessagesController
 * 
 * Gerencia mensagens do widget.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Http\Controllers\Api\V1\Widget
 */
class MessagesController extends BaseController
{
    /**
     * Lista mensagens da conversa
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $contact = $this->findOrCreateContact($request);
        $conversation = $this->findConversation($request, $contact);

        if (!$conversation) {
            return response()->json(['messages' => []]);
        }

        $messages = Message::where('conversation_id', $conversation->id)
            ->where('message_type', '!=', 'activity') // Filtra mensagens internas
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json(['messages' => $messages]);
    }

    /**
     * Cria nova mensagem
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        $this->validate($request, [
            'message.content' => 'required|string',
            'message.timestamp' => 'nullable|integer',
        ]);

        $contact = $this->findOrCreateContact($request);
        $conversation = $this->findConversation($request, $contact);

        // Cria conversa se não existir
        if (!$conversation) {
            $conversation = $this->createConversation($request, $contact);
        }

        $messageData = $request->input('message');
        $inbox = $this->getInbox($request);

        $message = Message::create([
            'account_id' => $inbox->account_id,
            'conversation_id' => $conversation->id,
            'inbox_id' => $inbox->id,
            'sender_type' => Contact::class,
            'sender_id' => $contact->id,
            'content' => $messageData['content'],
            'message_type' => 'incoming',
            'content_attributes' => [
                'in_reply_to' => $messageData['reply_to'] ?? null,
            ],
            'echo_id' => $messageData['echo_id'] ?? null,
        ]);

        return response()->json(['message' => $message], 201);
    }

    /**
     * Atualiza mensagem existente
     * 
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $message = Message::findOrFail($id);
        $contact = $this->findOrCreateContact($request);

        // Verifica se a mensagem pertence ao contato
        if ($message->sender_id !== $contact->id || $message->sender_type !== Contact::class) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $updateData = $request->input('message', []);

        if (isset($updateData['submitted_email'])) {
            // Atualiza email do contato
            $contact->update(['email' => $updateData['submitted_email']]);
            $message->update(['submitted_email' => $updateData['submitted_email']]);
        } else {
            $message->update($updateData);
        }

        return response()->json(['message' => $message]);
    }
}

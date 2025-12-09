<?php

namespace App\Http\Controllers\Api\V1\Widget;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller ConversationsController
 * 
 * Gerencia conversas do widget.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Http\Controllers\Api\V1\Widget
 */
class ConversationsController extends BaseController
{
    /**
     * Retorna conversa atual
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $contact = $this->findOrCreateContact($request);
        $conversation = $this->findConversation($request, $contact);

        if (!$conversation) {
            return response()->json(['conversation' => null]);
        }

        return response()->json(['conversation' => $conversation]);
    }

    /**
     * Cria nova conversa com primeira mensagem
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
        $conversation = $this->createConversation($request, $contact);
        $inbox = $this->getInbox($request);
        $messageData = $request->input('message');

        // Cria primeira mensagem
        $message = Message::create([
            'account_id' => $inbox->account_id,
            'conversation_id' => $conversation->id,
            'inbox_id' => $inbox->id,
            'sender_type' => \App\Models\Contact::class,
            'sender_id' => $contact->id,
            'content' => $messageData['content'],
            'message_type' => 'incoming',
            'content_attributes' => [
                'in_reply_to' => $messageData['reply_to'] ?? null,
            ],
            'echo_id' => $messageData['echo_id'] ?? null,
        ]);

        return response()->json([
            'conversation' => $conversation->fresh(),
            'message' => $message,
        ], 201);
    }

    /**
     * Atualiza último visto
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function updateLastSeen(Request $request): JsonResponse
    {
        $contact = $this->findOrCreateContact($request);
        $conversation = $this->findConversation($request, $contact);

        if (!$conversation) {
            return response()->json(['status' => 'ok']);
        }

        $conversation->update([
            'contact_last_seen_at' => now(),
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Resolve conversa
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function toggleStatus(Request $request): JsonResponse
    {
        $contact = $this->findOrCreateContact($request);
        $conversation = $this->findConversation($request, $contact);

        if (!$conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $channel = $this->getWebWidgetChannel($request);

        // Verifica se feature flag permite encerrar conversa
        // feature_flags bit 3 = end_conversation
        $canEndConversation = ($channel->feature_flags & 4) !== 0;

        if (!$canEndConversation) {
            return response()->json(['error' => 'Feature not enabled'], 403);
        }

        if ($conversation->status !== 'resolved') {
            $conversation->update(['status' => 'resolved']);
        }

        return response()->json(['status' => 'ok']);
    }
}

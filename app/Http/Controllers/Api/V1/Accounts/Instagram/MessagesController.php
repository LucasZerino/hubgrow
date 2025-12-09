<?php

namespace App\Http\Controllers\Api\V1\Accounts\Instagram;

use App\Http\Controllers\Api\V1\Accounts\BaseController;
use App\Models\Channel\InstagramChannel;
use App\Models\Inbox;
use App\Services\Instagram\OutgoingMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Controller MessagesController
 * 
 * Gerencia envio de mensagens e mídias para Instagram.
 * 
 * @package App\Http\Controllers\Api\V1\Accounts\Instagram
 */
class MessagesController extends BaseController
{
    /**
     * Envia mensagem de texto
     * 
     * @param Request $request
     * @param int $inboxId
     * @return JsonResponse
     */
    public function sendText(Request $request, int $inboxId): JsonResponse
    {
        $inbox = Inbox::where('account_id', $this->account->id)
            ->findOrFail($inboxId);

        if ($inbox->channel_type !== \App\Models\Channel\InstagramChannel::class) {
            return response()->json(['error' => 'Inbox is not an Instagram channel'], 400);
        }

        $channel = $inbox->channel;
        if (!$channel instanceof InstagramChannel) {
            return response()->json(['error' => 'Instagram channel not found'], 404);
        }

        $validated = $request->validate([
            'recipient_id' => 'required|string',
            'content' => 'required|string|max:1000',
        ]);

        try {
            $service = new OutgoingMessageService($channel);
            $response = $service->sendTextMessage(
                $validated['recipient_id'],
                $validated['content']
            );

            return response()->json([
                'message_id' => $response['message_id'] ?? null,
                'status' => 'sent',
            ], 201);
        } catch (\Exception $e) {
            Log::error('[INSTAGRAM] Failed to send text message', [
                'inbox_id' => $inboxId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to send message',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Envia imagem
     * 
     * @param Request $request
     * @param int $inboxId
     * @return JsonResponse
     */
    public function sendImage(Request $request, int $inboxId): JsonResponse
    {
        $inbox = Inbox::where('account_id', $this->account->id)
            ->findOrFail($inboxId);

        if ($inbox->channel_type !== \App\Models\Channel\InstagramChannel::class) {
            return response()->json(['error' => 'Inbox is not an Instagram channel'], 400);
        }

        $channel = $inbox->channel;
        if (!$channel instanceof InstagramChannel) {
            return response()->json(['error' => 'Instagram channel not found'], 404);
        }

        $validated = $request->validate([
            'recipient_id' => 'required|string',
            'image' => 'required|file|image|max:10240', // 10MB max
            'caption' => 'nullable|string|max:2200',
        ]);

        try {
            // Faz upload da imagem para MinIO/S3
            $path = $request->file('image')->store('instagram/images', 's3');
            $imageUrl = Storage::disk('s3')->url($path);

            $service = new OutgoingMessageService($channel);
            $response = $service->sendImage(
                $validated['recipient_id'],
                $imageUrl,
                $validated['caption'] ?? null
            );

            return response()->json([
                'message_id' => $response['message_id'] ?? null,
                'status' => 'sent',
                'image_url' => $imageUrl,
            ], 201);
        } catch (\Exception $e) {
            Log::error('[INSTAGRAM] Failed to send image', [
                'inbox_id' => $inboxId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to send image',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Envia vídeo
     * 
     * @param Request $request
     * @param int $inboxId
     * @return JsonResponse
     */
    public function sendVideo(Request $request, int $inboxId): JsonResponse
    {
        $inbox = Inbox::where('account_id', $this->account->id)
            ->findOrFail($inboxId);

        if ($inbox->channel_type !== \App\Models\Channel\InstagramChannel::class) {
            return response()->json(['error' => 'Inbox is not an Instagram channel'], 400);
        }

        $channel = $inbox->channel;
        if (!$channel instanceof InstagramChannel) {
            return response()->json(['error' => 'Instagram channel not found'], 404);
        }

        $validated = $request->validate([
            'recipient_id' => 'required|string',
            'video' => 'required|file|mimes:mp4,mov,avi|max:102400', // 100MB max
            'caption' => 'nullable|string|max:2200',
        ]);

        try {
            // Faz upload do vídeo para MinIO/S3
            $path = $request->file('video')->store('instagram/videos', 's3');
            $videoUrl = Storage::disk('s3')->url($path);

            $service = new OutgoingMessageService($channel);
            $response = $service->sendVideo(
                $validated['recipient_id'],
                $videoUrl,
                $validated['caption'] ?? null
            );

            return response()->json([
                'message_id' => $response['message_id'] ?? null,
                'status' => 'sent',
                'video_url' => $videoUrl,
            ], 201);
        } catch (\Exception $e) {
            Log::error('[INSTAGRAM] Failed to send video', [
                'inbox_id' => $inboxId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to send video',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}


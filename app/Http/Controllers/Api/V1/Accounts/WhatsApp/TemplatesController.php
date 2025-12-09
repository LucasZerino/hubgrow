<?php

namespace App\Http\Controllers\Api\V1\Accounts\WhatsApp;

use App\Http\Controllers\Api\V1\Accounts\BaseController;
use App\Models\Channel\WhatsAppChannel;
use App\Models\Inbox;
use App\Services\WhatsApp\SyncTemplatesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller TemplatesController
 * 
 * Gerencia sincronização de templates de mensagem do WhatsApp.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Http\Controllers\Api\V1\Accounts\WhatsApp
 */
class TemplatesController extends BaseController
{
    /**
     * Sincroniza templates do canal
     * 
     * POST /api/v1/accounts/{account_id}/whatsapp/templates/{inbox_id}/sync
     * 
     * @param Request $request
     * @param int $inboxId
     * @return JsonResponse
     */
    public function sync(Request $request, int $inboxId): JsonResponse
    {
        $inbox = \App\Support\Current::account()->inboxes()->findOrFail($inboxId);
        
        if (!($inbox->channel instanceof WhatsAppChannel)) {
            return response()->json([
                'error' => 'Channel is not a WhatsApp channel'
            ], 400);
        }

        try {
            $service = new SyncTemplatesService($inbox->channel);
            $service->perform();

            return response()->json([
                'success' => true,
                'message' => 'Templates synchronized successfully',
                'templates_count' => count($inbox->channel->message_templates ?? []),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Lista templates do canal
     * 
     * GET /api/v1/accounts/{account_id}/whatsapp/templates/{inbox_id}
     * 
     * @param Request $request
     * @param int $inboxId
     * @return JsonResponse
     */
    public function index(Request $request, int $inboxId): JsonResponse
    {
        $inbox = \App\Support\Current::account()->inboxes()->findOrFail($inboxId);
        
        if (!($inbox->channel instanceof WhatsAppChannel)) {
            return response()->json([
                'error' => 'Channel is not a WhatsApp channel'
            ], 400);
        }

        return response()->json([
            'success' => true,
            'templates' => $inbox->channel->message_templates ?? [],
            'last_updated' => $inbox->channel->message_templates_last_updated,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Accounts\WhatsApp;

use App\Http\Controllers\Api\V1\Accounts\BaseController;
use App\Models\Channel\WhatsAppChannel;
use App\Models\Inbox;
use App\Services\WhatsApp\HealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller HealthController
 * 
 * Retorna informações de saúde/status do canal WhatsApp.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Http\Controllers\Api\V1\Accounts\WhatsApp
 */
class HealthController extends BaseController
{
    /**
     * Busca status de saúde do canal
     * 
     * GET /api/v1/accounts/{account_id}/whatsapp/health/{inbox_id}
     * 
     * @param Request $request
     * @param int $inboxId
     * @return JsonResponse
     */
    public function show(Request $request, int $inboxId): JsonResponse
    {
        $inbox = \App\Support\Current::account()->inboxes()->findOrFail($inboxId);
        
        if (!($inbox->channel instanceof WhatsAppChannel)) {
            return response()->json([
                'error' => 'Channel is not a WhatsApp channel'
            ], 400);
        }

        try {
            $healthService = new HealthService($inbox->channel);
            $healthData = $healthService->fetchHealthStatus();

            return response()->json([
                'success' => true,
                'health' => $healthData,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

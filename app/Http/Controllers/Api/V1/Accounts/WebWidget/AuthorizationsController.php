<?php

namespace App\Http\Controllers\Api\V1\Accounts\WebWidget;

use App\Http\Controllers\Api\V1\Accounts\BaseController;
use App\Services\WebWidget\ChannelCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controller AuthorizationsController
 * 
 * Gerencia criação de canais WebWidget.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Http\Controllers\Api\V1\Accounts\WebWidget
 */
class AuthorizationsController extends BaseController
{
    /**
     * Cria canal WebWidget
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        // Verifica limite de canais WebWidget
        $account = $this->account;
        if (!$account->canCreateResource('webwidget_channels')) {
            $usage = $account->getResourceUsage('webwidget_channels');
            return response()->json([
                'error' => 'Limite de canais WebWidget excedido',
                'message' => 'Você atingiu o limite de canais WebWidget para esta conta.',
                'usage' => $usage,
            ], 402);
        }

        $this->validate($request, [
            'website_url' => 'required|url',
            'inbox_name' => 'nullable|string|max:255',
            'widget_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'welcome_title' => 'nullable|string|max:255',
            'welcome_tagline' => 'nullable|string|max:500',
            'reply_time' => 'nullable|integer|in:0,1,2',
            'pre_chat_form_enabled' => 'nullable|boolean',
            'pre_chat_form_options' => 'nullable|array',
            'continuity_via_email' => 'nullable|boolean',
            'hmac_mandatory' => 'nullable|boolean',
            'allowed_domains' => 'nullable|string',
        ]);

        try {
            $service = new ChannelCreationService($this->account, $request->all());
            $channel = $service->perform();

            return response()->json([
                'channel_id' => $channel->id,
                'inbox_id' => $channel->inbox->id,
                'website_token' => $channel->website_token,
                'hmac_token' => $channel->hmac_token,
                'widget_script' => $channel->getWebWidgetScript(),
            ], 201);

        } catch (\Exception $e) {
            Log::error("[WEBWIDGET] Failed to create channel: {$e->getMessage()}", [
                'account_id' => $this->account->id,
                'exception' => $e,
            ]);

            return response()->json([
                'error' => 'Failed to create WebWidget channel',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retorna script do widget
     * 
     * @param int $channelId
     * @return JsonResponse
     */
    public function getScript(Request $request, int $channel_id): JsonResponse
    {
        Log::info('[WEBWIDGET] getScript chamado', [
            'channel_id_param' => $channel_id,
            'route_param' => $request->route('channel_id'),
            'account_id' => $this->account->id
        ]);

        $channelId = (int) ($request->route('channel_id') ?? $channel_id);
        
        // Busca sem escopos globais para debug e robustez
        $channel = \App\Models\Channel\WebWidgetChannel::withoutGlobalScopes()->find($channelId);

        if (!$channel) {
            Log::error('[WEBWIDGET] Channel não encontrado no banco', ['channel_id' => $channelId]);
            return response()->json(['error' => 'Channel not found'], 404);
        }

        if ($channel->account_id !== $this->account->id) {
            Log::warning('[WEBWIDGET] Channel de outra conta', [
                'channel_id' => $channelId,
                'channel_account_id' => $channel->account_id,
                'request_account_id' => $this->account->id
            ]);
            return response()->json(['error' => 'Channel not found'], 404);
        }

        return response()->json([
            'widget_script' => $channel->getWebWidgetScript(),
            'website_token' => $channel->website_token,
        ]);
    }
}


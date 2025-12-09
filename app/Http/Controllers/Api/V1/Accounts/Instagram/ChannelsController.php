<?php

namespace App\Http\Controllers\Api\V1\Accounts\Instagram;

use App\Http\Controllers\Api\V1\Accounts\BaseController;
use App\Models\Channel\InstagramChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller ChannelsController
 * 
 * Gerencia canais Instagram da account.
 * Permite atualizar configurações do canal, incluindo webhook_url.
 * 
 * @package App\Http\Controllers\Api\V1\Accounts\Instagram
 */
class ChannelsController extends BaseController
{
    /**
     * Atualiza um canal Instagram
     * 
     * @param Request $request
     * @param int $channelId
     * @return JsonResponse
     */
    public function update(Request $request, int $channelId): JsonResponse
    {
        $channel = InstagramChannel::where('account_id', $this->account->id)
            ->findOrFail($channelId);

        $validated = $request->validate([
            'webhook_url' => 'nullable|url|max:500',
        ]);

        $channel->update($validated);
        $channel->load('inbox');

        return response()->json($channel);
    }

    /**
     * Mostra um canal Instagram específico
     * 
     * @param int $channelId
     * @return JsonResponse
     */
    public function show(int $channelId): JsonResponse
    {
        $channel = InstagramChannel::where('account_id', $this->account->id)
            ->with('inbox')
            ->findOrFail($channelId);

        return response()->json($channel);
    }
}


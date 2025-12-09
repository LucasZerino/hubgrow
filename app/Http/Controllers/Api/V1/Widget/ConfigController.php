<?php

namespace App\Http\Controllers\Api\V1\Widget;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller ConfigController
 * 
 * Retorna as configurações do widget para o frontend.
 * 
 * @package App\Http\Controllers\Api\V1\Widget
 */
class ConfigController extends BaseController
{
    /**
     * Retorna configurações do canal
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $websiteToken = $request->input('website_token') ?? $request->query('website_token');
        $channel = $this->getWebWidgetChannel($request);
        if (!$channel && $websiteToken) {
            $channel = \App\Models\Channel\WebWidgetChannel::where('website_token', $websiteToken)->first();
        }

        if (!$channel) {
            return response()->json([
                'welcome_title' => 'Chat with us',
                'welcome_tagline' => 'We are online',
                'greeting_message' => 'Hello! How can we help you today?',
                'widget_color' => '#1f93ff',
                'widget_logo' => null,
                'website_token' => $websiteToken ?? null,
                'pre_chat_form_enabled' => false,
                'pre_chat_form_options' => [],
                'enabled_features' => [
                    'attachments' => true,
                    'voice_recorder' => true,
                    'emoji_picker' => true,
                    'end_conversation' => true,
                ],
            ]);
        }

        return response()->json([
            'welcome_title' => $channel->welcome_title,
            'welcome_tagline' => $channel->welcome_tagline,
            'greeting_message' => $channel->greeting_message,
            'widget_color' => $channel->widget_color,
            'widget_logo' => $channel->widget_logo,
            'website_token' => $channel->website_token,
            'pre_chat_form_enabled' => $channel->pre_chat_form_enabled,
            'pre_chat_form_options' => $channel->pre_chat_form_options,
            'enabled_features' => [
                'attachments' => true,
                'voice_recorder' => true,
                'emoji_picker' => true,
                'end_conversation' => true,
            ],
        ]);
    }
}

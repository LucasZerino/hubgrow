<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente FacebookApiClient
 * 
 * Cliente HTTP para interagir com a Meta Graph API (WhatsApp Business API).
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\WhatsApp
 */
class FacebookApiClient
{
    protected const BASE_URI = 'https://graph.facebook.com';
    
    protected ?string $accessToken;
    protected string $apiVersion;

    /**
     * Construtor
     * 
     * @param string|null $accessToken Token de acesso (opcional)
     */
    public function __construct(?string $accessToken = null)
    {
        $this->accessToken = $accessToken;
        $this->apiVersion = config('services.whatsapp.api_version', 'v22.0');
    }

    /**
     * Troca código de autorização por access token
     * 
     * @param string $code Código de autorização do embedded signup
     * @return array
     */
    public function exchangeCodeForToken(string $code): array
    {
        $response = Http::get(self::BASE_URI . '/' . $this->apiVersion . '/oauth/access_token', [
            'client_id' => config('services.whatsapp.app_id'),
            'client_secret' => config('services.whatsapp.app_secret'),
            'code' => $code,
        ]);

        return $this->handleResponse($response, 'Token exchange failed');
    }

    /**
     * Busca números de telefone de um WABA
     * 
     * @param string $wabaId ID do WhatsApp Business Account
     * @return array
     */
    public function fetchPhoneNumbers(string $wabaId): array
    {
        $response = Http::withToken($this->accessToken)
            ->get(self::BASE_URI . '/' . $this->apiVersion . '/' . $wabaId . '/phone_numbers');

        return $this->handleResponse($response, 'WABA phone numbers fetch failed');
    }

    /**
     * Valida token e retorna informações de debug
     * 
     * @param string $inputToken Token a ser validado
     * @return array
     */
    public function debugToken(string $inputToken): array
    {
        $appAccessToken = $this->buildAppAccessToken();
        
        $response = Http::get(self::BASE_URI . '/' . $this->apiVersion . '/debug_token', [
            'input_token' => $inputToken,
            'access_token' => $appAccessToken,
        ]);

        return $this->handleResponse($response, 'Token validation failed');
    }

    /**
     * Registra número de telefone com PIN
     * 
     * @param string $phoneNumberId ID do número de telefone
     * @param string $pin PIN de 6 dígitos
     * @return array
     */
    public function registerPhoneNumber(string $phoneNumberId, string $pin): array
    {
        $response = Http::withToken($this->accessToken)
            ->post(self::BASE_URI . '/' . $this->apiVersion . '/' . $phoneNumberId . '/register', [
                'messaging_product' => 'whatsapp',
                'pin' => $pin,
            ]);

        return $this->handleResponse($response, 'Phone registration failed');
    }

    /**
     * Verifica se número de telefone está verificado
     * 
     * @param string $phoneNumberId ID do número de telefone
     * @return bool
     */
    public function isPhoneNumberVerified(string $phoneNumberId): bool
    {
        $response = Http::withToken($this->accessToken)
            ->get(self::BASE_URI . '/' . $this->apiVersion . '/' . $phoneNumberId, [
                'fields' => 'code_verification_status',
            ]);

        $data = $this->handleResponse($response, 'Phone status check failed');
        
        return ($data['code_verification_status'] ?? '') === 'VERIFIED';
    }

    /**
     * Inscreve webhook no WABA
     * 
     * @param string $wabaId ID do WhatsApp Business Account
     * @param string $callbackUrl URL do webhook
     * @param string $verifyToken Token de verificação
     * @return array
     */
    public function subscribeWabaWebhook(string $wabaId, string $callbackUrl, string $verifyToken): array
    {
        $response = Http::withToken($this->accessToken)
            ->post(self::BASE_URI . '/' . $this->apiVersion . '/' . $wabaId . '/subscribed_apps', [
                'override_callback_uri' => $callbackUrl,
                'verify_token' => $verifyToken,
            ]);

        return $this->handleResponse($response, 'Webhook subscription failed');
    }

    /**
     * Remove inscrição de webhook do WABA
     * 
     * @param string $wabaId ID do WhatsApp Business Account
     * @return array
     */
    public function unsubscribeWabaWebhook(string $wabaId): array
    {
        $response = Http::withToken($this->accessToken)
            ->delete(self::BASE_URI . '/' . $this->apiVersion . '/' . $wabaId . '/subscribed_apps');

        return $this->handleResponse($response, 'Webhook unsubscription failed');
    }

    /**
     * Busca informações de saúde do número de telefone
     * 
     * @param string $phoneNumberId ID do número de telefone
     * @return array
     */
    public function fetchPhoneHealth(string $phoneNumberId): array
    {
        $fields = [
            'quality_rating',
            'messaging_limit_tier',
            'code_verification_status',
            'account_mode',
            'id',
            'display_phone_number',
            'name_status',
            'verified_name',
            'webhook_configuration',
            'throughput',
            'last_onboarded_time',
            'platform_type',
            'certificate',
        ];

        $response = Http::withToken($this->accessToken)
            ->get(self::BASE_URI . '/' . $this->apiVersion . '/' . $phoneNumberId, [
                'fields' => implode(',', $fields),
            ]);

        return $this->handleResponse($response, 'Phone health fetch failed');
    }

    /**
     * Constrói app access token para debug_token
     * 
     * @return string
     */
    protected function buildAppAccessToken(): string
    {
        $appId = config('services.whatsapp.app_id');
        $appSecret = config('services.whatsapp.app_secret');
        
        return "{$appId}|{$appSecret}";
    }

    /**
     * Trata resposta HTTP
     * 
     * @param \Illuminate\Http\Client\Response $response
     * @param string $errorMessage
     * @return array
     * @throws \Exception
     */
    protected function handleResponse($response, string $errorMessage): array
    {
        if (!$response->successful()) {
            $error = "{$errorMessage}: {$response->body()}";
            Log::error("[WHATSAPP API] {$error}");
            throw new \Exception($error);
        }

        return $response->json();
    }
}


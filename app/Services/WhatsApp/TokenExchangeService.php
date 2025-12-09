<?php

namespace App\Services\WhatsApp;

/**
 * Serviço TokenExchangeService
 * 
 * Troca código de autorização do embedded signup por access token.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\WhatsApp
 */
class TokenExchangeService
{
    protected FacebookApiClient $apiClient;
    protected string $code;

    /**
     * Construtor
     * 
     * @param string $code Código de autorização
     */
    public function __construct(string $code)
    {
        $this->code = $code;
        $this->apiClient = new FacebookApiClient();
    }

    /**
     * Executa a troca de código por token
     * 
     * @return string Access token
     * @throws \Exception
     */
    public function perform(): string
    {
        $this->validateCode();

        $response = $this->apiClient->exchangeCodeForToken($this->code);
        $accessToken = $response['access_token'] ?? null;

        if (empty($accessToken)) {
            throw new \Exception("No access token in response: " . json_encode($response));
        }

        return $accessToken;
    }

    /**
     * Valida se o código foi fornecido
     * 
     * @return void
     * @throws \Exception
     */
    protected function validateCode(): void
    {
        if (empty($this->code)) {
            throw new \Exception('Authorization code is required');
        }
    }
}


<?php

namespace App\Services\Instagram;

/**
 * Serviço TokenExchangeService
 * 
 * Troca código de autorização por short-lived token e depois por long-lived token.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\Instagram
 */
class TokenExchangeService
{
    protected InstagramApiClient $apiClient;
    protected string $code;
    protected string $redirectUri;

    /**
     * Construtor
     * 
     * @param string $code Código de autorização
     * @param string $redirectUri URI de redirecionamento
     */
    public function __construct(string $code, string $redirectUri)
    {
        $this->code = $code;
        $this->redirectUri = $redirectUri;
        $this->apiClient = new InstagramApiClient();
    }

    /**
     * Executa a troca completa de tokens
     * 
     * @return array ['access_token' => string, 'expires_in' => int, 'token_type' => string]
     * @throws \Exception
     */
    public function perform(): array
    {
        $this->validateCode();

        // 1. Troca código por short-lived token
        $shortLivedResponse = $this->apiClient->exchangeCodeForToken($this->code, $this->redirectUri);
        $shortLivedToken = $shortLivedResponse['access_token'] ?? null;

        if (empty($shortLivedToken)) {
            throw new \Exception("No access token in short-lived response: " . json_encode($shortLivedResponse));
        }

        // 2. Troca short-lived por long-lived token (60 dias)
        $longLivedResponse = $this->apiClient->exchangeForLongLivedToken($shortLivedToken);

        return $longLivedResponse;
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


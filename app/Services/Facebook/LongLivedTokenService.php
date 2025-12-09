<?php

namespace App\Services\Facebook;

/**
 * Serviço LongLivedTokenService
 * 
 * Troca short-lived token por long-lived token (60 dias).
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\Facebook
 */
class LongLivedTokenService
{
    protected FacebookApiClient $apiClient;
    protected string $shortLivedToken;

    /**
     * Construtor
     * 
     * @param string $shortLivedToken Short-lived token
     */
    public function __construct(string $shortLivedToken)
    {
        $this->shortLivedToken = $shortLivedToken;
        $this->apiClient = new FacebookApiClient();
    }

    /**
     * Executa a troca de token
     * 
     * @return array ['access_token' => string, 'expires_in' => int, 'token_type' => string]
     * @throws \Exception
     */
    public function perform(): array
    {
        $this->validateToken();

        return $this->apiClient->exchangeForLongLivedToken($this->shortLivedToken);
    }

    /**
     * Valida se o token foi fornecido
     * 
     * @return void
     * @throws \Exception
     */
    protected function validateToken(): void
    {
        if (empty($this->shortLivedToken)) {
            throw new \Exception('Short-lived token is required');
        }
    }
}


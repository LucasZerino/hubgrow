<?php

namespace App\Services\WhatsApp;

/**
 * Serviço TokenValidationService
 * 
 * Valida se o access token tem acesso ao WABA especificado.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\WhatsApp
 */
class TokenValidationService
{
    protected FacebookApiClient $apiClient;
    protected string $accessToken;
    protected string $wabaId;

    /**
     * Construtor
     * 
     * @param string $accessToken Token de acesso
     * @param string $wabaId ID do WhatsApp Business Account
     */
    public function __construct(string $accessToken, string $wabaId)
    {
        $this->accessToken = $accessToken;
        $this->wabaId = $wabaId;
        $this->apiClient = new FacebookApiClient($accessToken);
    }

    /**
     * Executa a validação do token
     * 
     * @return void
     * @throws \Exception
     */
    public function perform(): void
    {
        $this->validateParameters();

        $tokenDebugData = $this->apiClient->debugToken($this->accessToken);
        $wabaScope = $this->extractWabaScope($tokenDebugData);
        $this->verifyWabaAuthorization($wabaScope);
    }

    /**
     * Valida parâmetros obrigatórios
     * 
     * @return void
     * @throws \Exception
     */
    protected function validateParameters(): void
    {
        if (empty($this->accessToken)) {
            throw new \Exception('Access token is required');
        }

        if (empty($this->wabaId)) {
            throw new \Exception('WABA ID is required');
        }
    }

    /**
     * Extrai scope do WABA dos dados de debug
     * 
     * @param array $tokenData Dados do debug_token
     * @return array
     * @throws \Exception
     */
    protected function extractWabaScope(array $tokenData): array
    {
        $granularScopes = $tokenData['data']['granular_scopes'] ?? [];
        
        foreach ($granularScopes as $scope) {
            if ($scope['scope'] === 'whatsapp_business_management') {
                return $scope;
            }
        }

        throw new \Exception('No WABA scope found in token');
    }

    /**
     * Verifica se o token tem acesso ao WABA
     * 
     * @param array $wabaScope Scope do WABA
     * @return void
     * @throws \Exception
     */
    protected function verifyWabaAuthorization(array $wabaScope): void
    {
        $authorizedWabaIds = $wabaScope['target_ids'] ?? [];

        if (in_array($this->wabaId, $authorizedWabaIds)) {
            return;
        }

        throw new \Exception(
            "Token does not have access to WABA {$this->wabaId}. " .
            "Authorized WABAs: " . implode(', ', $authorizedWabaIds)
        );
    }
}


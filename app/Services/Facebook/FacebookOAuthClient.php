<?php

namespace App\Services\Facebook;

/**
 * Serviço FacebookOAuthClient
 * 
 * Encapsula a lógica de construção de URLs OAuth do Facebook.
 * Similar ao InstagramOAuthClient e ao OAuth2::Client do Chatwoot.
 * 
 * @package App\Services\Facebook
 */
class FacebookOAuthClient
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $apiVersion;
    protected string $authorizeUrl;
    protected string $tokenUrl = 'https://graph.facebook.com/oauth/access_token';

    /**
     * Construtor
     * 
     * @param string $clientId App ID do Facebook
     * @param string $clientSecret App Secret do Facebook
     * @param string|null $apiVersion Versão da API (padrão: v22.0)
     */
    public function __construct(string $clientId, string $clientSecret, ?string $apiVersion = null)
    {
        $this->clientId = trim($clientId);
        $this->clientSecret = trim($clientSecret);
        $this->apiVersion = $apiVersion ?? config('services.facebook.api_version', 'v22.0');
        
        // URL de autorização do Facebook
        // Formato: https://www.facebook.com/v{version}/dialog/oauth
        $this->authorizeUrl = "https://www.facebook.com/{$this->apiVersion}/dialog/oauth";
        
        $this->validateCredentials();
    }

    /**
     * Cria instância a partir das credenciais do AppConfig
     * 
     * @return self
     * @throws \Exception
     */
    public static function fromAppConfig(): self
    {
        $clientId = \App\Support\AppConfigHelper::get('facebook', 'app_id');
        $clientSecret = \App\Support\AppConfigHelper::get('facebook', 'app_secret');
        
        if (!$clientId || !$clientSecret) {
            throw new \Exception('Facebook credentials not configured');
        }
        
        return new self($clientId, $clientSecret);
    }

    /**
     * Gera URL de autorização OAuth
     * 
     * Similar ao auth_code.authorize_url() do OAuth2::Client do Chatwoot
     * O client_id é adicionado automaticamente.
     * 
     * @param array $params Parâmetros adicionais (redirect_uri, scope, state, etc.)
     * @return string URL de autorização completa
     */
    public function getAuthorizationUrl(array $params): string
    {
        // Garante que response_type está definido
        if (!isset($params['response_type'])) {
            $params['response_type'] = 'code';
        }
        
        // Adiciona client_id PRIMEIRO (ordem pode ser importante para alguns OAuth providers)
        // Similar ao OAuth2::Client do Chatwoot que adiciona client_id automaticamente
        $orderedParams = [
            'client_id' => $this->clientId, // Primeiro parâmetro (padrão OAuth2)
        ];
        
        // Adiciona os demais parâmetros na ordem correta
        foreach ($params as $key => $value) {
            if ($key !== 'client_id') { // Evita duplicar client_id
                $orderedParams[$key] = $value;
            }
        }
        
        // IMPORTANTE: Meta/Facebook requer que redirect_uri seja codificado corretamente
        // Constrói query string manualmente para garantir controle total sobre codificação
        $queryParts = [];
        foreach ($orderedParams as $key => $value) {
            // Todos os valores devem usar rawurlencode para URLs completas
            // Isso garante que caracteres especiais na URL sejam codificados corretamente
            $queryParts[] = rawurlencode($key) . '=' . rawurlencode($value);
        }
        
        return $this->authorizeUrl . '?' . implode('&', $queryParts);
    }

    /**
     * Retorna o client_id (para uso em outras operações)
     * 
     * @return string
     */
    public function getClientId(): string
    {
        return $this->clientId;
    }

    /**
     * Retorna o client_secret (para uso em outras operações)
     * 
     * @return string
     */
    public function getClientSecret(): string
    {
        return $this->clientSecret;
    }

    /**
     * Retorna a versão da API
     * 
     * @return string
     */
    public function getApiVersion(): string
    {
        return $this->apiVersion;
    }

    /**
     * Valida credenciais
     * 
     * @return void
     * @throws \Exception
     */
    protected function validateCredentials(): void
    {
        if (empty($this->clientId)) {
            throw new \Exception('Facebook App ID não configurado');
        }
        
        if (empty($this->clientSecret)) {
            throw new \Exception('Facebook App Secret não configurado');
        }
        
        if (!is_numeric($this->clientId)) {
            throw new \Exception('Facebook App ID inválido: deve ser numérico');
        }
    }
}


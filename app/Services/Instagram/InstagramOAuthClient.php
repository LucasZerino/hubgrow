<?php

namespace App\Services\Instagram;

/**
 * Serviço InstagramOAuthClient
 * 
 * Encapsula a lógica de construção de URLs OAuth do Instagram.
 * Similar ao OAuth2::Client do Chatwoot, adiciona client_id automaticamente.
 * 
 * @package App\Services\Instagram
 */
class InstagramOAuthClient
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $authorizeUrl = 'https://api.instagram.com/oauth/authorize';
    protected string $tokenUrl = 'https://api.instagram.com/oauth/access_token';

    /**
     * Construtor
     * 
     * @param string $clientId App ID do Instagram
     * @param string $clientSecret App Secret do Instagram
     */
    public function __construct(string $clientId, string $clientSecret)
    {
        $this->clientId = trim($clientId);
        $this->clientSecret = trim($clientSecret);
        
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
        $clientId = \App\Support\AppConfigHelper::get('instagram', 'app_id');
        $clientSecret = \App\Support\AppConfigHelper::get('instagram', 'app_secret');
        
        if (!$clientId || !$clientSecret) {
            throw new \Exception('Instagram credentials not configured');
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
        
        // Parâmetros padrão para Instagram Business Login
        $defaultParams = [
            'enable_fb_login' => '0', // Desabilita login do Facebook
            'force_authentication' => '1', // Força autenticação
        ];
        
        // Merge com parâmetros padrão (parâmetros passados têm prioridade)
        $params = array_merge($defaultParams, $params);
        
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
        // http_build_query usa urlencode (que converte espaço em +)
        // Mas para URLs completas, precisamos usar rawurlencode (que converte espaço em %20)
        // Além disso, o redirect_uri já deve estar normalizado antes de chegar aqui
        
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
     * Valida credenciais
     * 
     * @return void
     * @throws \Exception
     */
    protected function validateCredentials(): void
    {
        if (empty($this->clientId)) {
            throw new \Exception('Instagram App ID não configurado');
        }
        
        if (empty($this->clientSecret)) {
            throw new \Exception('Instagram App Secret não configurado');
        }
        
        if (!is_numeric($this->clientId)) {
            throw new \Exception('Instagram App ID inválido: deve ser numérico');
        }
    }
}


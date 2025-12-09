<?php

namespace App\Services\Instagram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente InstagramApiClient
 * 
 * Cliente HTTP para interagir com a Instagram Graph API.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\Instagram
 */
class InstagramApiClient
{
    protected const BASE_URI = 'https://graph.instagram.com';
    protected const OAUTH_URI = 'https://api.instagram.com';
    
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
        $this->apiVersion = \App\Support\AppConfigHelper::get('instagram', 'api_version', 'v22.0');
    }

    /**
     * Troca código de autorização por short-lived token
     * 
     * @param string $code Código de autorização
     * @param string $redirectUri URI de redirecionamento
     * @return array
     */
    public function exchangeCodeForToken(string $code, string $redirectUri): array
    {
        $appId = \App\Support\AppConfigHelper::get('instagram', 'app_id');
        $appSecret = \App\Support\AppConfigHelper::get('instagram', 'app_secret');

        if (!$appId || !$appSecret) {
            throw new \Exception('Instagram credentials not configured');
        }

        $response = Http::asForm()->post(self::OAUTH_URI . '/oauth/access_token', [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if ($response->failed()) {
            $error = $response->json();
            $errorMessage = $error['error_message'] ?? $error['error'] ?? 'Token exchange failed';
            
            // Melhorar mensagens de erro comuns
            if (str_contains($errorMessage, 'Invalid platform app') || str_contains($errorMessage, 'platform')) {
                throw new \Exception('App ID inválido ou produto Instagram não configurado. Verifique se o App ID está correto e se o produto Instagram está adicionado à aplicação no Facebook Developers.');
            }
            
            throw new \Exception($errorMessage);
        }

        return $this->handleResponse($response, 'Token exchange failed');
    }

    /**
     * Troca short-lived token por long-lived token (60 dias)
     * 
     * @param string $shortLivedToken Short-lived token
     * @return array
     */
    public function exchangeForLongLivedToken(string $shortLivedToken): array
    {
        $appId = \App\Support\AppConfigHelper::get('instagram', 'app_id');
        $appSecret = \App\Support\AppConfigHelper::get('instagram', 'app_secret');
        
        if (!$appId || !$appSecret) {
            throw new \Exception('Instagram credentials not configured');
        }

        // IMPORTANTE: Instagram Business Login requer client_id na troca de token
        // Veja: https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/business-login
        $response = Http::get(self::BASE_URI . '/access_token', [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $appSecret,
            'access_token' => $shortLivedToken,
            'client_id' => $appId, // Obrigatório para Instagram Business Login
        ]);

        return $this->handleResponse($response, 'Long-lived token exchange failed');
    }

    /**
     * Renova long-lived token (extende validade)
     * 
     * @param string $longLivedToken Long-lived token atual
     * @return array
     */
    public function refreshLongLivedToken(string $longLivedToken): array
    {
        $response = Http::get(self::BASE_URI . '/refresh_access_token', [
            'grant_type' => 'ig_refresh_token',
            'access_token' => $longLivedToken,
        ]);

        return $this->handleResponse($response, 'Token refresh failed');
    }

    /**
     * Busca detalhes do usuário Instagram (próprio perfil)
     * 
     * @param string $accessToken Token de acesso
     * @return array
     */
    public function fetchUserDetails(string $accessToken): array
    {
        $response = Http::get(self::BASE_URI . '/' . $this->apiVersion . '/me', [
            'fields' => 'id,username,user_id,name,profile_picture_url,account_type',
            'access_token' => $accessToken,
        ]);

        return $this->handleResponse($response, 'Failed to fetch Instagram user details');
    }

    /**
     * Busca informações de um usuário Instagram específico
     * Usado para buscar perfil de contatos que enviam mensagens
     * Similar ao Chatwoot: fetch_instagram_user
     * 
     * @param string $instagramUserId ID do usuário Instagram
     * @param string $accessToken Token de acesso
     * @param \App\Models\Channel\InstagramChannel|null $channel Canal Instagram (opcional, para marcar erro de autorização)
     * @return array|null Retorna null se não conseguir buscar (ex: erro 230, 9010)
     */
    public function fetchInstagramUser(string $instagramUserId, string $accessToken, ?\App\Models\Channel\InstagramChannel $channel = null): ?array
    {
        $fields = 'name,username,profile_pic,follower_count,is_user_follow_business,is_business_follow_user,is_verified_user';
        
        $response = Http::get(self::BASE_URI . '/' . $this->apiVersion . '/' . $instagramUserId, [
            'fields' => $fields,
            'access_token' => $accessToken,
        ]);

        if (!$response->successful()) {
            $error = $response->json();
            $errorCode = $error['error']['code'] ?? null;
            $errorMessage = $error['error']['message'] ?? 'Unknown error';

            // Erro 190: Access token expired or invalid
            // Similar ao Chatwoot: channel.authorization_error! if error_code == 190
            if ($errorCode == 190) {
                Log::warning("[INSTAGRAM API] Access token expired or invalid for user {$instagramUserId}");
                
                // Marca canal como com erro de autorização
                if ($channel) {
                    $channel->authorizationError();
                }
                
                return null;
            }

            // Erro 230: User consent is required to access user profile
            // Ocorre quando o usuário nunca enviou mensagem antes
            // Podemos ignorar e criar contato com nome genérico
            if ($errorCode == 230) {
                Log::info("[INSTAGRAM API] User consent required for user {$instagramUserId} - creating unknown contact");
                return null;
            }

            // Erro 9010: No matching Instagram user
            // Ocorre quando o usuário não existe ou não é acessível
            if ($errorCode == 9010) {
                Log::info("[INSTAGRAM API] No matching Instagram user {$instagramUserId} - creating unknown contact");
                return null;
            }

            // Outros erros
            Log::warning("[INSTAGRAM API] Failed to fetch user {$instagramUserId}: {$errorMessage} (Code: {$errorCode})");
            return null;
        }

        $result = $response->json();
        
        return [
            'name' => $result['name'] ?? null,
            'username' => $result['username'] ?? null,
            'profile_pic' => $result['profile_pic'] ?? null,
            'id' => $result['id'] ?? $instagramUserId,
            'follower_count' => $result['follower_count'] ?? null,
            'is_user_follow_business' => $result['is_user_follow_business'] ?? null,
            'is_business_follow_user' => $result['is_business_follow_user'] ?? null,
            'is_verified_user' => $result['is_verified_user'] ?? null,
        ];
    }

    /**
     * Inscreve webhook no Instagram
     * 
     * @param string $instagramId ID da conta Instagram
     * @param array $subscribedFields Campos para inscrever
     * @return array
     */
    public function subscribeWebhook(string $instagramId, array $subscribedFields = null): array
    {
        $fields = $subscribedFields ?? ['messages', 'message_reactions', 'messaging_seen'];
        
        $response = Http::post(
            self::BASE_URI . '/' . $this->apiVersion . '/' . $instagramId . '/subscribed_apps',
            [
                'subscribed_fields' => implode(',', $fields),
                'access_token' => $this->accessToken,
            ]
        );

        return $this->handleResponse($response, 'Webhook subscription failed');
    }

    /**
     * Remove inscrição de webhook
     * 
     * @param string $instagramId ID da conta Instagram
     * @return array
     */
    public function unsubscribeWebhook(string $instagramId): array
    {
        $response = Http::delete(
            self::BASE_URI . '/' . $this->apiVersion . '/' . $instagramId . '/subscribed_apps',
            [
                'access_token' => $this->accessToken,
            ]
        );

        // DELETE pode retornar 200 mesmo se não houver inscrição
        return $response->json() ?? ['success' => true];
    }

    /**
     * Envia mensagem de texto
     * Similar ao Chatwoot: message_params
     * 
     * @param string $recipientId ID do destinatário (Instagram ID do contato)
     * @param string $message Texto da mensagem
     * @param string|null $instagramId ID do Instagram Business Account (opcional, usa 'me' se não fornecido)
     * @param bool $useHumanAgentTag Se deve usar tag HUMAN_AGENT (similar ao Chatwoot)
     * @return array
     */
    public function sendTextMessage(string $recipientId, string $message, ?string $instagramId = null, bool $useHumanAgentTag = false): array
    {
        // Usa o instagram_id fornecido ou 'me' como padrão
        $accountId = $instagramId ?? 'me';
        
        $payload = [
            'recipient' => ['id' => $recipientId],
            'message' => ['text' => $message],
        ];

        // Adiciona tag HUMAN_AGENT se configurado (similar ao Chatwoot: merge_human_agent_tag)
        if ($useHumanAgentTag) {
            $payload['messaging_type'] = 'MESSAGE_TAG';
            $payload['tag'] = 'HUMAN_AGENT';
        } else {
            $payload['messaging_type'] = 'RESPONSE';
        }
        
        $response = Http::withToken($this->accessToken)
            ->post(
                self::BASE_URI . '/' . $this->apiVersion . '/' . $accountId . '/messages',
                $payload
            );

        return $this->handleResponse($response, 'Failed to send message', null);
    }

    /**
     * Envia mídia (imagem, vídeo ou áudio)
     * Similar ao Chatwoot: attachment_message_params
     * 
     * @param string $recipientId ID do destinatário (Instagram ID do contato)
     * @param string $mediaType Tipo de mídia ('image', 'video' ou 'audio')
     * @param string $mediaUrl URL pública da mídia
     * @param string|null $instagramId ID da conta Instagram (do canal), opcional (usa 'me' se não fornecido)
     * @param string|null $caption Legenda opcional (Instagram não suporta caption em anexos, deve ser mensagem separada)
     * @param bool $useHumanAgentTag Se deve usar tag HUMAN_AGENT (similar ao Chatwoot)
     * @return array
     */
    public function sendMediaMessage(string $recipientId, string $mediaType, string $mediaUrl, ?string $instagramId = null, ?string $caption = null, bool $useHumanAgentTag = false): array
    {
        // Valida tipo de mídia (Instagram suporta: image, video, audio, file)
        $allowedTypes = ['image', 'video', 'audio', 'file'];
        if (!in_array($mediaType, $allowedTypes)) {
            throw new \InvalidArgumentException("Tipo de mídia não suportado: {$mediaType}. Tipos permitidos: " . implode(', ', $allowedTypes));
        }

        // Instagram não suporta 'file' diretamente, mas tentamos enviar
        // Para arquivos genéricos, Instagram pode rejeitar
        if ($mediaType === 'file') {
            $mediaType = 'file';
        }

        // Usa o instagram_id fornecido ou 'me' como padrão
        $accountId = $instagramId ?? 'me';

        // Formato similar ao Chatwoot: attachment_message_params
        $payload = [
            'recipient' => ['id' => $recipientId],
            'message' => [
                'attachment' => [
                    'type' => $mediaType,
                    'payload' => [
                        'url' => $mediaUrl,
                    ],
                ],
            ],
        ];

        // Adiciona tag HUMAN_AGENT se configurado (similar ao Chatwoot: merge_human_agent_tag)
        if ($useHumanAgentTag) {
            $payload['messaging_type'] = 'MESSAGE_TAG';
            $payload['tag'] = 'HUMAN_AGENT';
        } else {
            $payload['messaging_type'] = 'RESPONSE';
        }

        // Nota: Instagram não suporta caption em anexos via API
        // Caption deve ser enviada como mensagem de texto separada

        $response = Http::withToken($this->accessToken)
            ->post(
                self::BASE_URI . '/' . $this->apiVersion . '/' . $accountId . '/messages',
                $payload
            );

        return $this->handleResponse($response, 'Failed to send media message', null);
    }

    /**
     * Busca informações de um usuário Instagram por username usando Business Discovery
     * Similar ao Chatwoot: busca contatos para iniciar conversas proativas
     * 
     * IMPORTANTE: Requer que a conta seja Business ou Creator
     * A API do Instagram não permite listar seguidores diretamente por questões de privacidade
     * 
     * @param string $username Username do Instagram (sem @)
     * @param string $instagramBusinessAccountId ID da conta Instagram Business
     * @param string $accessToken Token de acesso
     * @param \App\Models\Channel\InstagramChannel|null $channel Canal Instagram (opcional)
     * @return array|null Retorna null se não encontrar ou erro
     */
    public function findUserByUsername(string $username, string $instagramBusinessAccountId, string $accessToken, ?\App\Models\Channel\InstagramChannel $channel = null): ?array
    {
        // Remove @ se presente
        $username = ltrim($username, '@');
        
        // Business Discovery API requer Facebook Graph API, não Instagram Graph API
        $facebookGraphApi = 'https://graph.facebook.com';
        
        try {
            $response = Http::get("{$facebookGraphApi}/{$this->apiVersion}/{$instagramBusinessAccountId}", [
                'fields' => "business_discovery.username({$username}){id,username,name,followers_count,profile_picture_url,biography}",
                'access_token' => $accessToken,
            ]);

            if (!$response->successful()) {
                $error = $response->json();
                $errorCode = $error['error']['code'] ?? null;
                $errorMessage = $error['error']['message'] ?? 'Unknown error';

                // Erro 190: Access token expired or invalid
                if ($errorCode == 190) {
                    Log::warning("[INSTAGRAM API] Access token expired or invalid for Business Discovery");
                    if ($channel) {
                        $channel->authorizationError();
                    }
                    return null;
                }

                // Erro 100: Invalid parameter (usuário não encontrado ou não é Business/Creator)
                if ($errorCode == 100) {
                    Log::info("[INSTAGRAM API] User not found or not a Business/Creator account: {$username}");
                    return null;
                }

                Log::warning("[INSTAGRAM API] Failed to find user by username {$username}: {$errorMessage} (Code: {$errorCode})");
                return null;
            }

            $result = $response->json();
            $businessDiscovery = $result['business_discovery'] ?? null;

            if (!$businessDiscovery) {
                Log::info("[INSTAGRAM API] Business Discovery returned no data for username: {$username}");
                return null;
            }

            return [
                'id' => $businessDiscovery['id'] ?? null,
                'username' => $businessDiscovery['username'] ?? null,
                'name' => $businessDiscovery['name'] ?? null,
                'profile_pic' => $businessDiscovery['profile_picture_url'] ?? null,
                'follower_count' => $businessDiscovery['followers_count'] ?? null,
                'biography' => $businessDiscovery['biography'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error("[INSTAGRAM API] Exception while finding user by username: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Trata resposta HTTP
     * Similar ao Chatwoot: process_response e external_error
     * 
     * @param \Illuminate\Http\Client\Response $response
     * @param string $errorMessage
     * @param \App\Models\Channel\InstagramChannel|null $channel Canal Instagram (opcional, para marcar erro de autorização)
     * @return array
     * @throws \Exception
     */
    protected function handleResponse($response, string $errorMessage, ?\App\Models\Channel\InstagramChannel $channel = null): array
    {
        if (!$response->successful()) {
            $errorData = $response->json();
            $errorCode = $errorData['error']['code'] ?? null;
            $errorMsg = $errorData['error']['message'] ?? $errorMessage;
            
            // Erro 190: Access token expired or invalid (similar ao Chatwoot)
            // https://developers.facebook.com/docs/messenger-platform/error-codes
            if ($errorCode == 190 && $channel) {
                Log::warning("[INSTAGRAM API] Access token expired or invalid (code 190)");
                $channel->authorizationError();
            }
            
            $error = "{$errorMessage}: {$errorMsg} (Code: {$errorCode})";
            Log::error("[INSTAGRAM API] {$error}");
            throw new \Exception($error);
        }

        $parsedResponse = $response->json();
        
        // Verifica se há erro na resposta mesmo com status 200 (similar ao Chatwoot)
        if (isset($parsedResponse['error'])) {
            $errorCode = $parsedResponse['error']['code'] ?? null;
            $errorMsg = $parsedResponse['error']['message'] ?? $errorMessage;
            
            // Erro 190: Access token expired or invalid
            if ($errorCode == 190 && $channel) {
                Log::warning("[INSTAGRAM API] Access token expired or invalid (code 190)");
                $channel->authorizationError();
            }
            
            $error = "{$errorMessage}: {$errorMsg} (Code: {$errorCode})";
            Log::error("[INSTAGRAM API] {$error}");
            throw new \Exception($error);
        }

        return $parsedResponse;
    }
}


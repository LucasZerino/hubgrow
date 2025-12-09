<?php

namespace App\Services\Facebook;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Cliente FacebookApiClient
 * 
 * Cliente HTTP para interagir com a Facebook Graph API.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\Facebook
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
        $this->apiVersion = config('services.facebook.api_version', 'v22.0');
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
        $oauthClient = FacebookOAuthClient::fromAppConfig();
        
        $response = Http::get(self::BASE_URI . '/oauth/access_token', [
            'client_id' => $oauthClient->getClientId(),
            'client_secret' => $oauthClient->getClientSecret(),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        return $this->handleResponse($response, 'Code exchange failed');
    }

    /**
     * Troca short-lived token por long-lived token (60 dias)
     * 
     * @param string $shortLivedToken Short-lived token
     * @return array
     */
    public function exchangeForLongLivedToken(string $shortLivedToken): array
    {
        $oauthClient = FacebookOAuthClient::fromAppConfig();
        
        $response = Http::get(self::BASE_URI . '/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $oauthClient->getClientId(),
            'client_secret' => $oauthClient->getClientSecret(),
            'fb_exchange_token' => $shortLivedToken,
        ]);

        return $this->handleResponse($response, 'Long-lived token exchange failed');
    }

    /**
     * Busca páginas do usuário
     * 
     * @param string $userAccessToken Token de acesso do usuário
     * @return array
     */
    public function fetchUserPages(string $userAccessToken): array
    {
        $response = Http::get(
            self::BASE_URI . '/' . $this->apiVersion . '/me/accounts',
            [
                'access_token' => $userAccessToken,
                'fields' => 'id,name,access_token,category,tasks',
            ]
        );

        return $this->handleResponse($response, 'Failed to fetch user pages');
    }

    /**
     * Busca detalhes de uma página
     * 
     * @param string $pageId ID da página
     * @param string $pageAccessToken Token de acesso da página
     * @return array
     */
    public function fetchPageDetails(string $pageId, string $pageAccessToken): array
    {
        $response = Http::get(
            self::BASE_URI . '/' . $this->apiVersion . '/' . $pageId,
            [
                'access_token' => $pageAccessToken,
                'fields' => 'id,name,category,instagram_business_account',
            ]
        );

        return $this->handleResponse($response, 'Failed to fetch page details');
    }

    /**
     * Busca Instagram Business Account associado à página
     * 
     * @param string $pageId ID da página
     * @param string $pageAccessToken Token de acesso da página
     * @return string|null Instagram ID
     */
    public function fetchInstagramBusinessAccount(string $pageId, string $pageAccessToken): ?string
    {
        try {
            $pageDetails = $this->fetchPageDetails($pageId, $pageAccessToken);
            return $pageDetails['instagram_business_account']['id'] ?? null;
        } catch (\Exception $e) {
            Log::warning("[FACEBOOK] Failed to fetch Instagram Business Account: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Busca informações de um usuário do Facebook
     * 
     * @param string $userId ID do usuário do Facebook
     * @param string $pageAccessToken Token de acesso da página
     * @return array|null
     */
    public function fetchUserProfile(string $userId, string $pageAccessToken): ?array
    {
        try {
            $url = self::BASE_URI . '/' . $this->apiVersion . '/' . $userId;
            
            Log::info('[FACEBOOK API] Buscando perfil do usuário', [
                'user_id' => $userId,
                'url' => $url,
                'has_token' => !empty($pageAccessToken),
                'token_length' => strlen($pageAccessToken ?? ''),
            ]);

            $response = Http::timeout(10)->get(
                $url,
                [
                    'access_token' => $pageAccessToken,
                    'fields' => 'id,name,first_name,last_name,profile_pic',
                ]
            );

            if (!$response->successful()) {
                $errorBody = $response->json();
                $statusCode = $response->status();
                
                Log::warning("[FACEBOOK API] Falha ao buscar perfil do usuário: HTTP {$statusCode}", [
                    'user_id' => $userId,
                    'status_code' => $statusCode,
                    'error' => $errorBody,
                    'error_message' => $errorBody['error']['message'] ?? null,
                    'error_type' => $errorBody['error']['type'] ?? null,
                    'error_code' => $errorBody['error']['code'] ?? null,
                    'response_body' => $response->body(),
                ]);
                
                // Se for erro 400, pode ser que o usuário não permitiu acesso ao perfil
                // ou o token não tem permissão. Não é crítico, apenas loga.
                if ($statusCode === 400) {
                    $errorMessage = $errorBody['error']['message'] ?? 'Unknown error';
                    Log::info('[FACEBOOK API] Erro 400 ao buscar perfil', [
                        'user_id' => $userId,
                        'error_message' => $errorMessage,
                        'possible_causes' => [
                            'Usuário não permitiu acesso ao perfil',
                            'Token não tem permissão pages_messaging',
                            'Token expirado ou inválido',
                        ],
                    ]);
                }
                
                // Se for erro 401, token inválido
                if ($statusCode === 401) {
                    Log::error('[FACEBOOK API] Token inválido ou expirado', [
                        'user_id' => $userId,
                    ]);
                }
                
                return null;
            }

            $userInfo = $response->json();
            
            // Valida se tem pelo menos um campo de nome
            $hasName = isset($userInfo['name']) && !empty($userInfo['name']);
            $hasFirstName = isset($userInfo['first_name']) && !empty($userInfo['first_name']);
            
            Log::info('[FACEBOOK API] Perfil do usuário obtido', [
                'user_id' => $userId,
                'has_name' => $hasName,
                'has_first_name' => $hasFirstName,
                'name' => $userInfo['name'] ?? null,
                'first_name' => $userInfo['first_name'] ?? null,
                'last_name' => $userInfo['last_name'] ?? null,
                'has_profile_pic' => isset($userInfo['profile_pic']),
                'all_fields' => array_keys($userInfo),
            ]);
            
            // Se não tem nome, retorna null para tentar novamente
            if (!$hasName && !$hasFirstName) {
                Log::warning('[FACEBOOK API] Perfil obtido mas sem nome', [
                    'user_id' => $userId,
                    'userInfo' => $userInfo,
                ]);
                return null;
            }

            return $userInfo;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("[FACEBOOK API] Erro de conexão ao buscar perfil: {$e->getMessage()}", [
                'user_id' => $userId,
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error("[FACEBOOK API] Erro ao buscar perfil: {$e->getMessage()}", [
                'user_id' => $userId,
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Inscreve webhook na página
     * 
     * A API do Facebook aceita subscribed_fields como:
     * - String separada por vírgulas: "messages,message_deliveries,..."
     * - Array: ["messages", "message_deliveries", ...]
     * 
     * Usamos array para compatibilidade com o formato esperado pela API.
     * 
     * @param string $pageId ID da página
     * @param array $subscribedFields Campos para inscrever
     * @return array
     */
    public function subscribeWebhook(string $pageId, array $subscribedFields = null): array
    {
        $fields = $subscribedFields ?? [
            'messages',
            'messaging_postbacks',
            'message_deliveries',
            'message_reads',
            'message_echoes',
        ];
        
        // Campos de assinatura do webhook do Facebook:
        // messages, messaging_postbacks, message_deliveries, message_reads, message_echoes
        $fieldsString = implode(',', $fields);
        
        Log::info('[FACEBOOK] Inscrevendo webhook na página', [
            'page_id' => $pageId,
            'fields' => $fields,
            'fields_string' => $fieldsString,
            'fields_count' => count($fields),
            'api_version' => $this->apiVersion,
            'url' => self::BASE_URI . '/' . $this->apiVersion . '/' . $pageId . '/subscribed_apps',
        ]);
        
        // A API do Facebook aceita subscribed_fields como string separada por vírgulas
        // Formato: "messages,message_deliveries,message_echoes,message_reads,standby,messaging_handovers"
        // Este é o mesmo formato usado pelo Chatwoot original
        $response = Http::post(
            self::BASE_URI . '/' . $this->apiVersion . '/' . $pageId . '/subscribed_apps',
            [
                'subscribed_fields' => $fieldsString, // String separada por vírgulas
                'access_token' => $this->accessToken,
            ]
        );

        $result = $this->handleResponse($response, 'Webhook subscription failed');
        
        Log::info('[FACEBOOK] Webhook inscrito com sucesso', [
            'page_id' => $pageId,
            'response' => $result,
            'status_code' => $response->status(),
        ]);
        
        return $result;
    }

    /**
     * Remove inscrição de webhook
     * 
     * @param string $pageId ID da página
     * @return array
     */
    public function unsubscribeWebhook(string $pageId): array
    {
        $response = Http::delete(
            self::BASE_URI . '/' . $this->apiVersion . '/' . $pageId . '/subscribed_apps',
            [
                'access_token' => $this->accessToken,
            ]
        );

        // DELETE pode retornar 200 mesmo se não houver inscrição
        return $response->json() ?? ['success' => true];
    }

    /**
     * Envia mensagem de texto
     * 
     * @param string $recipientId ID do destinatário
     * @param string $message Texto da mensagem
     * @param string $pageId ID da página
     * @return array
     */
    public function sendTextMessage(string $recipientId, string $message, string $pageId): array
    {
        Log::info('[FACEBOOK API] Enviando mensagem de texto', [
            'recipient_id' => $recipientId,
            'page_id' => $pageId,
            'message_length' => strlen($message),
        ]);

        $url = self::BASE_URI . '/' . $this->apiVersion . '/' . $pageId . '/messages';
        $payload = [
            'recipient' => ['id' => $recipientId],
            'message' => ['text' => $message],
            'messaging_type' => 'RESPONSE',
        ];

        $startTime = microtime(true);

        $response = Http::withToken($this->accessToken)
            ->post($url, $payload);

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        // Log no banco de dados (http_logs)
        try {
            $context = [
                'method' => 'POST',
                'url' => $url,
                'request_body' => json_encode($payload),
                'response_status' => $response->status(),
                'response_body' => $response->body(),
                'duration_ms' => $duration,
                'timestamp_br' => now()->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i:s'),
                'recipient_id' => $recipientId,
                'page_id' => $pageId,
            ];

            DB::table('http_logs')->insert([
                'level' => $response->successful() ? 'INFO' : 'ERROR',
                'message' => "[FACEBOOK API] POST {$url} ({$response->status()})",
                'context' => json_encode($context),
                'channel' => 'facebook_api',
                'created_at' => now()->setTimezone('America/Sao_Paulo'),
            ]);
        } catch (\Exception $e) {
            Log::error('[FACEBOOK API] Erro ao salvar log no banco', ['error' => $e->getMessage()]);
        }

        if ($response->successful()) {
            Log::info('[FACEBOOK API] Mensagem enviada com sucesso', [
                'recipient_id' => $recipientId,
                'response' => $response->json(),
            ]);
        } else {
            Log::error('[FACEBOOK API] Falha ao enviar mensagem', [
                'recipient_id' => $recipientId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }

        return $this->handleResponse($response, 'Failed to send message');
    }

    /**
     * Envia mensagem com anexo (imagem, vídeo, áudio, arquivo)
     * Similar ao Chatwoot: fb_attachment_message_params
     * 
     * @param string $recipientId ID do destinatário
     * @param string $attachmentUrl URL pública do anexo
     * @param string $attachmentType Tipo: 'image', 'video', 'audio', 'file'
     * @param string $pageId ID da página
     * @return array
     */
    public function sendAttachmentMessage(
        string $recipientId, 
        string $attachmentUrl, 
        string $attachmentType, 
        string $pageId
    ): array {
        // Valida tipo de anexo
        $allowedTypes = ['image', 'video', 'audio', 'file'];
        if (!in_array($attachmentType, $allowedTypes)) {
            throw new \InvalidArgumentException("Tipo de anexo não suportado: {$attachmentType}. Tipos permitidos: " . implode(', ', $allowedTypes));
        }

        $url = self::BASE_URI . '/' . $this->apiVersion . '/' . $pageId . '/messages';
        $payload = [
            'recipient' => ['id' => $recipientId],
            'message' => [
                'attachment' => [
                    'type' => $attachmentType,
                    'payload' => [
                        'url' => $attachmentUrl,
                    ],
                ],
            ],
            'messaging_type' => 'RESPONSE',
        ];

        $startTime = microtime(true);

        $response = Http::withToken($this->accessToken)
            ->post($url, $payload);

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        // Log no banco de dados (http_logs)
        try {
            $context = [
                'method' => 'POST',
                'url' => $url,
                'request_body' => json_encode($payload),
                'response_status' => $response->status(),
                'response_body' => $response->body(),
                'duration_ms' => $duration,
                'timestamp_br' => now()->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i:s'),
                'recipient_id' => $recipientId,
                'page_id' => $pageId,
                'attachment_type' => $attachmentType,
            ];

            DB::table('http_logs')->insert([
                'level' => $response->successful() ? 'INFO' : 'ERROR',
                'message' => "[FACEBOOK API] POST {$url} ({$response->status()})",
                'context' => json_encode($context),
                'channel' => 'facebook_api',
                'created_at' => now()->setTimezone('America/Sao_Paulo'),
            ]);
        } catch (\Exception $e) {
            Log::error('[FACEBOOK API] Erro ao salvar log no banco', ['error' => $e->getMessage()]);
        }

        return $this->handleResponse($response, 'Failed to send attachment');
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
            Log::error("[FACEBOOK API] {$error}");
            throw new \Exception($error);
        }

        return $response->json();
    }
}


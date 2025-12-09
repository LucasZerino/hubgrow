<?php

namespace App\Services\Instagram;

use App\Models\Channel\InstagramChannel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Serviço RefreshOauthTokenService
 * 
 * Renova tokens long-lived do Instagram quando necessário.
 * Tokens são válidos por 60 dias e podem ser renovados.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\Instagram
 */
class RefreshOauthTokenService
{
    protected InstagramChannel $channel;
    protected InstagramApiClient $apiClient;

    /**
     * Construtor
     * 
     * @param InstagramChannel $channel
     */
    public function __construct(InstagramChannel $channel)
    {
        $this->channel = $channel;
        $this->apiClient = new InstagramApiClient($channel->access_token);
    }

    /**
     * Retorna token válido, renovando se necessário
     * 
     * @return string|null Access token válido
     */
    public function getAccessToken(): ?string
    {
        if (!$this->isTokenValid()) {
            return null;
        }

        // Se token é válido mas não elegível para refresh, retorna atual
        if (!$this->isTokenEligibleForRefresh()) {
            return $this->channel->access_token;
        }

        return $this->attemptTokenRefresh();
    }

    /**
     * Verifica se token ainda é válido (não expirado)
     * 
     * @return bool
     */
    protected function isTokenValid(): bool
    {
        if (!$this->channel->expires_at) {
            return false;
        }

        return Carbon::now()->lt($this->channel->expires_at);
    }

    /**
     * Verifica se token é elegível para refresh
     * 
     * Condições (todas devem ser verdadeiras):
     * 1. Token ainda é válido
     * 2. Token tem pelo menos 24 horas
     * 3. Token expira em menos de 10 dias
     * 
     * @return bool
     */
    protected function isTokenEligibleForRefresh(): bool
    {
        // 1. Token ainda válido
        $tokenIsValid = $this->isTokenValid();

        // 2. Token tem pelo menos 24 horas
        $tokenIsOldEnough = $this->channel->updated_at 
            && Carbon::now()->diffInHours($this->channel->updated_at) >= 24;

        // 3. Token expira em menos de 10 dias
        $approachingExpiry = $this->channel->expires_at 
            && Carbon::now()->addDays(10)->gt($this->channel->expires_at);

        return $tokenIsValid && $tokenIsOldEnough && $approachingExpiry;
    }

    /**
     * Tenta renovar o token
     * 
     * @return string Token (novo ou atual em caso de falha)
     */
    protected function attemptTokenRefresh(): string
    {
        try {
            $refreshedTokenData = $this->apiClient->refreshLongLivedToken($this->channel->access_token);
            $this->updateChannelTokens($refreshedTokenData);
            
            return $this->channel->fresh()->access_token;
        } catch (\Exception $e) {
            Log::error("[INSTAGRAM] Token refresh failed: {$e->getMessage()}");
            // Retorna token atual mesmo se refresh falhar
            return $this->channel->access_token;
        }
    }

    /**
     * Atualiza tokens do canal
     * 
     * @param array $tokenData Dados do token renovado
     * @return void
     */
    protected function updateChannelTokens(array $tokenData): void
    {
        $expiresAt = Carbon::now()->addSeconds($tokenData['expires_in'] ?? 5184000); // 60 dias padrão

        $this->channel->update([
            'access_token' => $tokenData['access_token'],
            'expires_at' => $expiresAt,
        ]);
    }
}


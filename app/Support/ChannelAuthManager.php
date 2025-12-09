<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * ChannelAuthManager
 * 
 * Gerencia whitelist/blacklist para canais públicos com autenticação manual.
 * Valida token na primeira conexão e armazena resultado em Redis.
 */
class ChannelAuthManager
{
    /**
     * Prefixo para chaves Redis
     */
    private const WHITELIST_PREFIX = 'channel:whitelist:';
    private const BLACKLIST_PREFIX = 'channel:blacklist:';
    
    /**
     * TTL em segundos (24 horas)
     */
    private const TTL = 86400;

    /**
     * Verifica se o token está na whitelist
     * 
     * @param string $token
     * @param int $accountId
     * @return bool
     */
    public function isWhitelisted(string $token, int $accountId): bool
    {
        $key = $this->getWhitelistKey($token, $accountId);
        return Redis::exists($key) > 0;
    }

    /**
     * Verifica se o token está na blacklist
     * 
     * @param string $token
     * @param int $accountId
     * @return bool
     */
    public function isBlacklisted(string $token, int $accountId): bool
    {
        $key = $this->getBlacklistKey($token, $accountId);
        return Redis::exists($key) > 0;
    }

    /**
     * Adiciona token à whitelist
     * 
     * @param string $token
     * @param int $accountId
     * @param int $userId
     * @return void
     */
    public function addToWhitelist(string $token, int $accountId, int $userId): void
    {
        $key = $this->getWhitelistKey($token, $accountId);
        Redis::setex($key, self::TTL, $userId);
        
        error_log("[CHANNEL AUTH] ✅ Token adicionado à whitelist: User ID={$userId} | Account ID={$accountId}");
    }

    /**
     * Adiciona token à blacklist
     * 
     * @param string $token
     * @param int $accountId
     * @return void
     */
    public function addToBlacklist(string $token, int $accountId): void
    {
        $key = $this->getBlacklistKey($token, $accountId);
        Redis::setex($key, self::TTL, time());
        
        error_log("[CHANNEL AUTH] ❌ Token adicionado à blacklist: Account ID={$accountId}");
    }

    /**
     * Valida token e acesso à account
     * 
     * @param string $token
     * @param int $accountId
     * @return array{valid: bool, user: \App\Models\User|null, reason: string|null}
     */
    public function validateTokenAndAccess(string $token, int $accountId): array
    {
        // Verifica se está na blacklist
        if ($this->isBlacklisted($token, $accountId)) {
            return [
                'valid' => false,
                'user' => null,
                'reason' => 'Token está na blacklist'
            ];
        }

        // Verifica se está na whitelist
        if ($this->isWhitelisted($token, $accountId)) {
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken) {
                return [
                    'valid' => true,
                    'user' => $accessToken->tokenable,
                    'reason' => null
                ];
            }
        }

        // Primeira validação - verifica token
        $accessToken = PersonalAccessToken::findToken($token);
        
        if (!$accessToken) {
            $this->addToBlacklist($token, $accountId);
            return [
                'valid' => false,
                'user' => null,
                'reason' => 'Token inválido ou expirado'
            ];
        }

        $user = $accessToken->tokenable;

        // Verifica acesso à account
        if (!$user->isSuperAdmin() && !$user->hasAccessToAccount($accountId)) {
            $this->addToBlacklist($token, $accountId);
            return [
                'valid' => false,
                'user' => null,
                'reason' => 'Usuário sem acesso à account'
            ];
        }

        // Token válido e usuário tem acesso - adiciona à whitelist
        $this->addToWhitelist($token, $accountId, $user->id);
        
        return [
            'valid' => true,
            'user' => $user,
            'reason' => null
        ];
    }

    /**
     * Remove token da whitelist (útil para logout)
     * 
     * @param string $token
     * @param int $accountId
     * @return void
     */
    public function removeFromWhitelist(string $token, int $accountId): void
    {
        $key = $this->getWhitelistKey($token, $accountId);
        Redis::del($key);
    }

    /**
     * Limpa whitelist/blacklist de um token
     * 
     * @param string $token
     * @param int $accountId
     * @return void
     */
    public function clear(string $token, int $accountId): void
    {
        $this->removeFromWhitelist($token, $accountId);
        $blacklistKey = $this->getBlacklistKey($token, $accountId);
        Redis::del($blacklistKey);
    }

    /**
     * Gera chave Redis para whitelist
     * 
     * @param string $token
     * @param int $accountId
     * @return string
     */
    private function getWhitelistKey(string $token, int $accountId): string
    {
        $tokenHash = hash('sha256', $token);
        return self::WHITELIST_PREFIX . "{$accountId}:{$tokenHash}";
    }

    /**
     * Gera chave Redis para blacklist
     * 
     * @param string $token
     * @param int $accountId
     * @return string
     */
    private function getBlacklistKey(string $token, int $accountId): string
    {
        $tokenHash = hash('sha256', $token);
        return self::BLACKLIST_PREFIX . "{$accountId}:{$tokenHash}";
    }
}


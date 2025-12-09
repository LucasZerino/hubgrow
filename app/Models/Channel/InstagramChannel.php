<?php

namespace App\Models\Channel;

use App\Models\Account;
use App\Models\Concerns\Channelable;
use App\Models\Concerns\HasAccountScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model InstagramChannel
 * 
 * Representa um canal de Instagram conectado via Meta Graph API.
 * Implementa o contrato Channelable seguindo SOLID.
 * 
 * @package App\Models\Channel
 */
class InstagramChannel extends Model
{
    use HasFactory, Channelable;

    protected $table = 'instagram_channels';

    protected $fillable = [
        'account_id',
        'instagram_id',
        'access_token',
        'expires_at',
        'webhook_url',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Boot do model - aplica global scope
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new HasAccountScope);
    }

    /**
     * Relacionamento com Account
     * 
     * @return BelongsTo
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Retorna o nome do canal
     * 
     * @return string
     */
    public function getName(): string
    {
        return 'Instagram';
    }

    /**
     * Configura os webhooks do canal
     * 
     * @return void
     */
    public function setupWebhooks(): void
    {
        try {
            $apiClient = new \App\Services\Instagram\InstagramApiClient($this->access_token);
            $apiClient->subscribeWebhook($this->instagram_id);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning(
                "[INSTAGRAM] Webhook subscription failed: {$e->getMessage()}"
            );
        }
    }

    /**
     * Remove os webhooks do canal
     * 
     * @return void
     */
    public function teardownWebhooks(): void
    {
        try {
            $apiClient = new \App\Services\Instagram\InstagramApiClient($this->access_token);
            $apiClient->unsubscribeWebhook($this->instagram_id);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning(
                "[INSTAGRAM] Webhook unsubscription failed: {$e->getMessage()}"
            );
        }
    }

    /**
     * Retorna access token válido, renovando se necessário
     * 
     * @return string|null
     */
    public function getAccessToken(): ?string
    {
        $refreshService = new \App\Services\Instagram\RefreshOauthTokenService($this);
        return $refreshService->getAccessToken();
    }

    /**
     * Cria contact_inbox para um contato do Instagram
     * Similar ao Chatwoot: create_contact_inbox(instagram_id, name)
     * 
     * @param string $instagramId Instagram ID do contato (source_id)
     * @param string $name Nome do contato
     * @return \App\Models\ContactInbox
     */
    public function createContactInbox(string $instagramId, string $name): \App\Models\ContactInbox
    {
        $inbox = $this->inbox;

        if (!$inbox) {
            throw new \RuntimeException('Instagram channel must have an associated inbox');
        }

        // Usa ContactInboxWithContactBuilder para criar contato e contact_inbox
        $builder = new \App\Builders\ContactInboxWithContactBuilder(
            $inbox,
            [
                'name' => $name,
                'identifier_instagram' => $instagramId,
            ],
            $instagramId,
            false // hmac_verified
        );

        return $builder->perform();
    }

    /**
     * Verifica se reautorização é necessária
     * Similar ao Chatwoot: reauthorization_required?
     * 
     * @return bool
     */
    public function isReauthorizationRequired(): bool
    {
        try {
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $key = sprintf(
                \App\Support\Redis\RedisKeys::REAUTHORIZATION_REQUIRED,
                'instagram_channel',
                $this->id
            );
            
            return $redis->exists($key) > 0;
        } catch (\Exception $e) {
            // Se Redis não estiver disponível, retorna false (assume que não requer reautorização)
            \Illuminate\Support\Facades\Log::warning("[INSTAGRAM] Erro ao verificar reautorização no Redis", [
                'channel_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Marca erro de autorização
     * Similar ao Chatwoot: authorization_error!
     * 
     * @return void
     */
    public function authorizationError(): void
    {
        try {
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $countKey = sprintf(
                \App\Support\Redis\RedisKeys::AUTHORIZATION_ERROR_COUNT,
                'instagram_channel',
                $this->id
            );
            $requiredKey = sprintf(
                \App\Support\Redis\RedisKeys::REAUTHORIZATION_REQUIRED,
                'instagram_channel',
                $this->id
            );

            // Incrementa contador de erros
            $count = $redis->incr($countKey);
            
            // Threshold do Instagram (1 erro, conforme Chatwoot)
            $threshold = 1;
            
            if ($count >= $threshold) {
                // Marca como requerendo reautorização
                $redis->set($requiredKey, true);
                
                \Illuminate\Support\Facades\Log::warning("[INSTAGRAM] Canal requer reautorização", [
                    'channel_id' => $this->id,
                    'error_count' => $count,
                ]);
            }
        } catch (\Exception $e) {
            // Se Redis não estiver disponível, apenas loga o erro mas não interrompe o fluxo
            \Illuminate\Support\Facades\Log::warning("[INSTAGRAM] Erro ao marcar erro de autorização no Redis", [
                'channel_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Marca como reautorizado
     * Similar ao Chatwoot: reauthorized!
     * 
     * @return void
     */
    public function markAsReauthorized(): void
    {
        try {
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $countKey = sprintf(
                \App\Support\Redis\RedisKeys::AUTHORIZATION_ERROR_COUNT,
                'instagram_channel',
                $this->id
            );
            $requiredKey = sprintf(
                \App\Support\Redis\RedisKeys::REAUTHORIZATION_REQUIRED,
                'instagram_channel',
                $this->id
            );

            $redis->del($countKey);
            $redis->del($requiredKey);
        } catch (\Exception $e) {
            // Se Redis não estiver disponível, apenas loga o erro
            \Illuminate\Support\Facades\Log::warning("[INSTAGRAM] Erro ao limpar flags de reautorização do Redis", [
                'channel_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
        }
        
        \Illuminate\Support\Facades\Log::info("[INSTAGRAM] Canal reautorizado", [
            'channel_id' => $this->id,
        ]);
    }
}

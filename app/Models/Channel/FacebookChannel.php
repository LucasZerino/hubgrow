<?php

namespace App\Models\Channel;

use App\Models\Account;
use App\Models\Concerns\Channelable;
use App\Models\Concerns\HasAccountScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model FacebookChannel
 * 
 * Representa um canal de Facebook Messenger conectado via Meta Graph API.
 * Implementa o contrato Channelable seguindo SOLID.
 * 
 * @package App\Models\Channel
 */
class FacebookChannel extends Model
{
    use HasFactory, Channelable;

    protected $table = 'facebook_channels';

    protected $fillable = [
        'account_id',
        'page_id',
        'page_access_token',
        'user_access_token',
        'instagram_id',
        'webhook_url',
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
        return 'Facebook';
    }

    /**
     * Configura os webhooks do canal
     * 
     * @return void
     */
    public function setupWebhooks(): void
    {
        try {
            $apiClient = new \App\Services\Facebook\FacebookApiClient($this->page_access_token);
            $apiClient->subscribeWebhook($this->page_id);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning(
                "[FACEBOOK] Webhook subscription failed: {$e->getMessage()}"
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
            $apiClient = new \App\Services\Facebook\FacebookApiClient($this->page_access_token);
            $apiClient->unsubscribeWebhook($this->page_id);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning(
                "[FACEBOOK] Webhook unsubscription failed: {$e->getMessage()}"
            );
        }
    }

    /**
     * Marca como reautorizado
     * Similar ao Chatwoot: reauthorized!
     * Limpa flags de erro de autorização no Redis
     * 
     * @return void
     */
    public function markAsReauthorized(): void
    {
        try {
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $countKey = sprintf(
                \App\Support\Redis\RedisKeys::AUTHORIZATION_ERROR_COUNT,
                'facebook_channel',
                $this->id
            );
            $requiredKey = sprintf(
                \App\Support\Redis\RedisKeys::REAUTHORIZATION_REQUIRED,
                'facebook_channel',
                $this->id
            );

            $redis->del($countKey);
            $redis->del($requiredKey);
            
            \Illuminate\Support\Facades\Log::info('[FACEBOOK] Canal marcado como reautorizado', [
                'channel_id' => $this->id,
            ]);
        } catch (\Exception $e) {
            // Se Redis não estiver disponível, apenas loga o erro
            \Illuminate\Support\Facades\Log::warning("[FACEBOOK] Erro ao limpar flags de reautorização do Redis", [
                'channel_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cria contact_inbox para um contato do Facebook
     * Similar ao Chatwoot: create_contact_inbox(facebook_id, name)
     * 
     * @param string $facebookId Facebook ID do contato (source_id)
     * @param string $name Nome do contato
     * @return \App\Models\ContactInbox
     */
    public function createContactInbox(string $facebookId, string $name): \App\Models\ContactInbox
    {
        $inbox = $this->inbox;

        if (!$inbox) {
            throw new \RuntimeException('Facebook channel must have an associated inbox');
        }

        // Usa ContactInboxWithContactBuilder para criar contato e contact_inbox
        $builder = new \App\Builders\ContactInboxWithContactBuilder(
            $inbox,
            [
                'name' => $name,
                'identifier_facebook' => $facebookId,
            ],
            $facebookId,
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
                'facebook_channel',
                $this->id
            );
            
            return $redis->exists($key) > 0;
        } catch (\Exception $e) {
            // Se Redis não estiver disponível, retorna false
            \Illuminate\Support\Facades\Log::warning("[FACEBOOK] Erro ao verificar reautorização no Redis", [
                'channel_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

<?php

namespace App\Models\Channel;

use App\Models\Account;
use App\Models\Concerns\Channelable;
use App\Models\Concerns\HasAccountScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Model WhatsAppChannel
 * 
 * Representa um canal de WhatsApp conectado via Meta Cloud API.
 * Implementa o contrato Channelable seguindo SOLID.
 * 
 * @package App\Models\Channel
 */
class WhatsAppChannel extends Model
{
    use HasFactory, Channelable;

    /**
     * Providers disponíveis
     */
    public const PROVIDER_WHATSAPP_CLOUD = 'whatsapp_cloud';
    public const PROVIDER_DEFAULT = 'default';

    protected $table = 'whatsapp_channels';

    protected $fillable = [
        'account_id',
        'phone_number',
        'provider',
        'provider_config',
        'message_templates',
        'message_templates_last_updated',
    ];

    protected $casts = [
        'provider_config' => 'array',
        'message_templates' => 'array',
        'message_templates_last_updated' => 'datetime',
    ];

    /**
     * Boot do model - aplica global scope e gera webhook token
     */
    protected static function booted(): void
    {
        static::creating(function ($channel) {
            if ($channel->provider === self::PROVIDER_WHATSAPP_CLOUD) {
                $config = $channel->provider_config ?? [];
                if (empty($config['webhook_verify_token'])) {
                    $config['webhook_verify_token'] = Str::random(32);
                    $channel->provider_config = $config;
                }
            }
        });

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
        return 'WhatsApp';
    }

    /**
     * Configura os webhooks do canal
     * 
     * @return void
     * @throws \Exception
     */
    public function setupWebhooks(): void
    {
        $wabaId = $this->provider_config['business_account_id'] ?? null;
        $accessToken = $this->provider_config['api_key'] ?? null;

        if (!$wabaId || !$accessToken) {
            throw new \Exception('WABA ID or access token missing');
        }

        $service = new \App\Services\WhatsApp\WebhookSetupService($this, $wabaId, $accessToken);
        $service->perform();
    }

    /**
     * Remove os webhooks do canal
     * 
     * @return void
     */
    public function teardownWebhooks(): void
    {
        $wabaId = $this->provider_config['business_account_id'] ?? null;
        $accessToken = $this->provider_config['api_key'] ?? null;

        if (!$wabaId || !$accessToken) {
            return; // Silenciosamente falha se não tiver credenciais
        }

        try {
            $apiClient = new \App\Services\WhatsApp\FacebookApiClient($accessToken);
            $apiClient->unsubscribeWabaWebhook($wabaId);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning(
                "[WHATSAPP] Webhook teardown failed: {$e->getMessage()}"
            );
        }
    }
}

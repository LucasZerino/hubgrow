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
 * Model WebWidgetChannel
 * 
 * Representa um canal de webchat (widget JavaScript).
 * Implementa o contrato Channelable seguindo SOLID.
 * 
 * @package App\Models\Channel
 */
class WebWidgetChannel extends Model
{
    use HasFactory, Channelable;

    protected $table = 'web_widget_channels';

    /**
     * Reply times possíveis
     */
    public const REPLY_TIME_FEW_MINUTES = 0;
    public const REPLY_TIME_FEW_HOURS = 1;
    public const REPLY_TIME_ONE_DAY = 2;

    protected $fillable = [
        'account_id',
        'website_token',
        'hmac_token',
        'website_url',
        'widget_logo',
        'widget_color',
        'welcome_title',
        'welcome_tagline',
        'greeting_message',
        'webhook_url',
        'reply_time',
        'pre_chat_form_enabled',
        'pre_chat_form_options',
        'continuity_via_email',
        'hmac_mandatory',
        'allowed_domains',
        'feature_flags',
    ];

    protected $casts = [
        'pre_chat_form_enabled' => 'boolean',
        'continuity_via_email' => 'boolean',
        'hmac_mandatory' => 'boolean',
        'pre_chat_form_options' => 'array',
        'feature_flags' => 'integer',
        'reply_time' => 'integer',
    ];

    /**
     * Boot do model - gera tokens automaticamente
     */
    protected static function booted(): void
    {
        static::creating(function ($channel) {
            if (empty($channel->website_token)) {
                $channel->website_token = Str::random(32);
            }
            if (empty($channel->hmac_token)) {
                $channel->hmac_token = Str::random(32);
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
        return 'Website';
    }

    /**
     * Gera o script do widget para embed
     * 
     * @return string
     */
    public function getWebWidgetScript(): string
    {
        $baseUrl = config('app.url', '');
        
        return sprintf(
            '<script>
                (function(d,t) {
                    var BASE_URL="%s";
                    var g=d.createElement(t),s=d.getElementsByTagName(t)[0];
                    g.src=BASE_URL+"/packs/js/sdk.js";
                    g.async = true;
                    s.parentNode.insertBefore(g,s);
                    g.onload=function(){
                        window.growhubSDK.run({
                            websiteToken: "%s",
                            baseUrl: BASE_URL
                        })
                    }
                })(document,"script");
            </script>',
            $baseUrl,
            $this->website_token
        );
    }

    /**
     * Configura os webhooks do canal
     * 
     * @return void
     */
    public function setupWebhooks(): void
    {
        // WebWidget não requer webhooks externos
    }

    /**
     * Remove os webhooks do canal
     * 
     * @return void
     */
    public function teardownWebhooks(): void
    {
        // WebWidget não requer webhooks externos
    }
}

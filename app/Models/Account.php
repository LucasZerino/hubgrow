<?php

namespace App\Models;

use App\Models\Concerns\HasAccountScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model Account
 * 
 * Representa uma organização/empresa (tenant) no sistema.
 * Todos os dados são isolados por Account (multi-tenancy).
 * 
 * @package App\Models
 */
class Account extends Model
{
    use HasFactory;

    /**
     * Status possíveis da conta
     */
    public const STATUS_ACTIVE = 0;
    public const STATUS_SUSPENDED = 1;

    /**
     * Locales suportados
     */
    public const LOCALE_EN = 0;
    public const LOCALE_PT_BR = 1;

    protected $fillable = [
        'name',
        'domain',
        'support_email',
        'locale',
        'status',
        'auto_resolve_duration',
        'custom_attributes',
        'internal_attributes',
        'settings',
        'limits',
        'feature_flags',
    ];

    protected $casts = [
        'custom_attributes' => 'array',
        'internal_attributes' => 'array',
        'settings' => 'array',
        'limits' => 'array',
        'feature_flags' => 'integer',
        'status' => 'integer',
        'locale' => 'integer',
    ];

    /**
     * Relacionamento com Inboxes
     * 
     * @return HasMany
     */
    public function inboxes(): HasMany
    {
        return $this->hasMany(Inbox::class);
    }

    /**
     * Relacionamento com Contacts
     * 
     * @return HasMany
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * Relacionamento com Conversations
     * 
     * @return HasMany
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Relacionamento com Messages
     * 
     * @return HasMany
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Relacionamento com AccountUsers
     * 
     * @return HasMany
     */
    public function accountUsers(): HasMany
    {
        return $this->hasMany(AccountUser::class);
    }

    /**
     * Relacionamento com ApiKeys
     * 
     * @return HasMany
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /**
     * Verifica se a conta está ativa
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Verifica se a conta está suspensa
     * 
     * @return bool
     */
    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * Retorna limite para um recurso específico
     * 
     * @param string $resource Nome do recurso (inboxes, agents, whatsapp_channels, etc)
     * @return int Limite ou -1 para ilimitado
     */
    public function getLimit(string $resource): int
    {
        $limits = $this->limits ?? [];
        
        // Se tem limite específico definido, retorna
        if (isset($limits[$resource])) {
            return $limits[$resource] === -1 ? PHP_INT_MAX : (int) $limits[$resource];
        }

        // Retorna ilimitado por padrão (para desenvolvimento)
        // Em produção, pode retornar um limite padrão
        return PHP_INT_MAX;
    }

    /**
     * Verifica se pode criar um recurso específico
     * 
     * @param string $resource Nome do recurso
     * @param string|null $channelType Tipo de canal (para limites específicos)
     * @return bool
     */
    public function canCreateResource(string $resource, ?string $channelType = null): bool
    {
        $limit = $this->getLimit($resource);
        
        // Ilimitado
        if ($limit === PHP_INT_MAX) {
            return true;
        }

        // Conta quantos recursos existem
        $currentCount = $this->getResourceCount($resource, $channelType);
        
        return $currentCount < $limit;
    }

    /**
     * Conta recursos existentes
     * 
     * @param string $resource
     * @param string|null $channelType
     * @return int
     */
    protected function getResourceCount(string $resource, ?string $channelType = null): int
    {
        return match ($resource) {
            'inboxes' => $this->inboxes()->count(),
            'agents' => $this->accountUsers()->where('role', AccountUser::ROLE_AGENT)->count(),
            'whatsapp_channels' => $this->inboxes()
                ->where('channel_type', \App\Models\Channel\WhatsAppChannel::class)
                ->count(),
            'instagram_channels' => $this->inboxes()
                ->where('channel_type', \App\Models\Channel\InstagramChannel::class)
                ->count(),
            'facebook_channels' => $this->inboxes()
                ->where('channel_type', \App\Models\Channel\FacebookChannel::class)
                ->count(),
            'webwidget_channels' => $this->inboxes()
                ->where('channel_type', \App\Models\Channel\WebWidgetChannel::class)
                ->count(),
            default => 0,
        };
    }

    /**
     * Define limite para um recurso
     * 
     * @param string $resource
     * @param int $limit -1 para ilimitado
     * @return void
     */
    public function setLimit(string $resource, int $limit): void
    {
        $limits = $this->limits ?? [];
        $limits[$resource] = $limit;
        $this->limits = $limits;
        $this->save();
    }

    /**
     * Retorna uso atual de um recurso
     * 
     * @param string $resource
     * @param string|null $channelType
     * @return array ['current' => int, 'limit' => int, 'available' => int]
     */
    public function getResourceUsage(string $resource, ?string $channelType = null): array
    {
        $current = $this->getResourceCount($resource, $channelType);
        $limit = $this->getLimit($resource);
        $available = $limit === PHP_INT_MAX ? PHP_INT_MAX : max(0, $limit - $current);

        return [
            'current' => $current,
            'limit' => $limit === PHP_INT_MAX ? -1 : $limit,
            'available' => $available === PHP_INT_MAX ? -1 : $available,
        ];
    }
}

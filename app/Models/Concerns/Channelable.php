<?php

namespace App\Models\Concerns;

use App\Models\Inbox;

/**
 * Trait Channelable
 * 
 * Define o contrato comum para todos os canais de comunicação.
 * Aplica o princípio de Interface Segregation (SOLID).
 * 
 * @package App\Models\Concerns
 */
trait Channelable
{
    /**
     * Retorna o nome do canal
     * 
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Relacionamento com Inbox
     * Cada canal tem um inbox associado
     * 
     * @return \Illuminate\Database\Eloquent\Relations\MorphOne
     */
    public function inbox()
    {
        return $this->morphOne(Inbox::class, 'channel', 'channel_type', 'channel_id');
    }

    /**
     * Verifica se o canal requer reautorização
     * 
     * @return bool
     */
    public function requiresReauthorization(): bool
    {
        return false;
    }

    /**
     * Configura os webhooks do canal
     * 
     * @return void
     */
    public function setupWebhooks(): void
    {
        // Implementação específica em cada canal
    }

    /**
     * Remove os webhooks do canal
     * 
     * @return void
     */
    public function teardownWebhooks(): void
    {
        // Implementação específica em cada canal
    }
}


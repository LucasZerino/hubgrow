<?php

namespace App\Services\Base;

use App\Models\Message;

/**
 * Interface SendMessageServiceInterface
 * 
 * Define o contrato para serviços de envio de mensagens.
 * Aplica o princípio de Interface Segregation (SOLID).
 * 
 * @package App\Services\Base
 */
interface SendMessageServiceInterface
{
    /**
     * Envia uma mensagem
     * 
     * @param Message $message Mensagem a ser enviada
     * @return void
     */
    public function send(Message $message): void;
}


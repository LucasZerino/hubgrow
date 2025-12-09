<?php

namespace App\Services\Base;

/**
 * Interface IncomingMessageServiceInterface
 * 
 * Define o contrato para serviços de processamento de mensagens recebidas.
 * Aplica o princípio de Interface Segregation (SOLID).
 * 
 * @package App\Services\Base
 */
interface IncomingMessageServiceInterface
{
    /**
     * Processa uma mensagem recebida
     * 
     * @param array $params Parâmetros da mensagem
     * @return void
     */
    public function process(array $params): void;
}


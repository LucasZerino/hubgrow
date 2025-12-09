<?php

namespace App\Services\Facebook;

/**
 * Classe MessageParser
 * 
 * Parseia payload do Facebook Messenger para extrair informações.
 * Similar ao Integrations::Facebook::MessageParser do Chatwoot.
 * 
 * @package App\Services\Facebook
 */
class MessageParser
{
    protected array $response;
    protected ?array $messaging;

    /**
     * Construtor
     * 
     * @param string|array $responseJson JSON string ou array
     */
    public function __construct($responseJson)
    {
        if (is_string($responseJson)) {
            $this->response = json_decode($responseJson, true);
        } else {
            $this->response = $responseJson;
        }

        // Se o payload já é um objeto messaging (enviado diretamente do controller),
        // usa ele diretamente. Caso contrário, tenta extrair de response['messaging'] ou response['standby']
        if (isset($this->response['sender']) && isset($this->response['recipient'])) {
            // Payload é um objeto messaging direto
            $this->messaging = $this->response;
        } else {
            // Payload é o formato completo do webhook
            $this->messaging = $this->response['messaging'] ?? $this->response['standby'] ?? null;
        }

        // Log para debug
        \Illuminate\Support\Facades\Log::info('[FACEBOOK MESSAGE PARSER] Parser inicializado', [
            'has_messaging' => $this->messaging !== null,
            'response_keys' => is_array($this->response) ? array_keys($this->response) : 'not_array',
            'messaging_keys' => $this->messaging ? array_keys($this->messaging) : 'null',
            'has_sender' => isset($this->messaging['sender']),
            'has_recipient' => isset($this->messaging['recipient']),
            'has_message' => isset($this->messaging['message']),
        ]);
    }

    /**
     * Retorna sender ID
     * 
     * @return string|null
     */
    public function getSenderId(): ?string
    {
        if (!$this->messaging || !isset($this->messaging['sender'])) {
            return null;
        }
        return $this->messaging['sender']['id'] ?? null;
    }

    /**
     * Retorna recipient ID
     * 
     * @return string|null
     */
    public function getRecipientId(): ?string
    {
        if (!$this->messaging || !isset($this->messaging['recipient'])) {
            return null;
        }
        return $this->messaging['recipient']['id'] ?? null;
    }

    /**
     * Retorna timestamp
     * 
     * @return int|null
     */
    public function getTimestamp(): ?int
    {
        return $this->messaging['timestamp'] ?? null;
    }

    /**
     * Retorna conteúdo da mensagem
     * 
     * @return string|null
     */
    public function getContent(): ?string
    {
        if (!$this->messaging || !isset($this->messaging['message'])) {
            return null;
        }
        return $this->messaging['message']['text'] ?? null;
    }

    /**
     * Retorna sequence number
     * 
     * @return int|null
     */
    public function getSequence(): ?int
    {
        return $this->messaging['message']['seq'] ?? null;
    }

    /**
     * Retorna attachments
     * 
     * @return array|null
     */
    public function getAttachments(): ?array
    {
        if (!$this->messaging || !isset($this->messaging['message'])) {
            return null;
        }
        return $this->messaging['message']['attachments'] ?? null;
    }

    /**
     * Retorna message ID
     * 
     * @return string|null
     */
    public function getMessageId(): ?string
    {
        if (!$this->messaging || !isset($this->messaging['message'])) {
            return null;
        }
        return $this->messaging['message']['mid'] ?? null;
    }

    /**
     * Retorna delivery status
     * 
     * @return array|null
     */
    public function getDelivery(): ?array
    {
        return $this->messaging['delivery'] ?? null;
    }

    /**
     * Retorna read status
     * 
     * @return array|null
     */
    public function getRead(): ?array
    {
        return $this->messaging['read'] ?? null;
    }

    /**
     * Retorna read watermark
     * 
     * @return int|null
     */
    public function getReadWatermark(): ?int
    {
        return $this->messaging['read']['watermark'] ?? null;
    }

    /**
     * Retorna delivery watermark
     * 
     * @return int|null
     */
    public function getDeliveryWatermark(): ?int
    {
        return $this->messaging['delivery']['watermark'] ?? null;
    }

    /**
     * Verifica se é echo (mensagem enviada por nós)
     * 
     * @return bool
     */
    public function isEcho(): bool
    {
        if (!$this->messaging || !isset($this->messaging['message'])) {
            return false;
        }
        return !empty($this->messaging['message']['is_echo']);
    }

    /**
     * Retorna app ID
     * 
     * @return string|null
     */
    public function getAppId(): ?string
    {
        return $this->messaging['message']['app_id'] ?? null;
    }

    /**
     * Verifica se foi enviado do nosso app
     * 
     * @return bool
     */
    public function isSentFromOurApp(): bool
    {
        $appId = $this->getAppId();
        $ourAppId = config('services.facebook.app_id');
        
        return $appId && $appId == $ourAppId;
    }

    /**
     * Verifica se é mensagem de agente via echo
     * 
     * @return bool
     */
    public function isAgentMessageViaEcho(): bool
    {
        return $this->isEcho() && !$this->isSentFromOurApp();
    }

    /**
     * Retorna payload completo
     * 
     * @return array
     */
    public function getResponse(): array
    {
        return $this->response;
    }

    /**
     * Retorna messaging object
     * 
     * @return array|null
     */
    public function getMessaging(): ?array
    {
        return $this->messaging;
    }
}


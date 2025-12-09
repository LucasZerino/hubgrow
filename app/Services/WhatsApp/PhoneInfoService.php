<?php

namespace App\Services\WhatsApp;

/**
 * Serviço PhoneInfoService
 * 
 * Busca informações do número de telefone do WABA.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\WhatsApp
 */
class PhoneInfoService
{
    protected FacebookApiClient $apiClient;
    protected string $wabaId;
    protected ?string $phoneNumberId;
    protected string $accessToken;

    /**
     * Construtor
     * 
     * @param string $wabaId ID do WhatsApp Business Account
     * @param string|null $phoneNumberId ID do número de telefone (opcional)
     * @param string $accessToken Token de acesso
     */
    public function __construct(string $wabaId, ?string $phoneNumberId, string $accessToken)
    {
        $this->wabaId = $wabaId;
        $this->phoneNumberId = $phoneNumberId;
        $this->accessToken = $accessToken;
        $this->apiClient = new FacebookApiClient($accessToken);
    }

    /**
     * Executa a busca de informações do telefone
     * 
     * @return array Informações do telefone
     * @throws \Exception
     */
    public function perform(): array
    {
        $this->validateParameters();

        $response = $this->apiClient->fetchPhoneNumbers($this->wabaId);
        $phoneNumbers = $response['data'] ?? [];

        if (empty($phoneNumbers)) {
            throw new \Exception("No phone numbers found for WABA {$this->wabaId}");
        }

        $phoneData = $this->findPhoneData($phoneNumbers);

        if (!$phoneData) {
            throw new \Exception("No phone numbers found for WABA {$this->wabaId}");
        }

        return $this->buildPhoneInfo($phoneData);
    }

    /**
     * Valida parâmetros obrigatórios
     * 
     * @return void
     * @throws \Exception
     */
    protected function validateParameters(): void
    {
        if (empty($this->wabaId)) {
            throw new \Exception('WABA ID is required');
        }

        if (empty($this->accessToken)) {
            throw new \Exception('Access token is required');
        }
    }

    /**
     * Encontra dados do telefone específico ou retorna o primeiro
     * 
     * @param array $phoneNumbers Lista de números
     * @return array|null
     */
    protected function findPhoneData(array $phoneNumbers): ?array
    {
        if (empty($phoneNumbers)) {
            return null;
        }

        if ($this->phoneNumberId) {
            foreach ($phoneNumbers as $phone) {
                if ($phone['id'] === $this->phoneNumberId) {
                    return $phone;
                }
            }
        }

        return $phoneNumbers[0] ?? null;
    }

    /**
     * Constrói array com informações do telefone
     * 
     * @param array $phoneData Dados brutos da API
     * @return array
     */
    protected function buildPhoneInfo(array $phoneData): array
    {
        $displayPhoneNumber = $this->sanitizePhoneNumber($phoneData['display_phone_number'] ?? '');

        return [
            'phone_number_id' => $phoneData['id'],
            'phone_number' => '+' . $displayPhoneNumber,
            'verified' => ($phoneData['code_verification_status'] ?? '') === 'VERIFIED',
            'business_name' => $phoneData['verified_name'] ?? $phoneData['display_phone_number'] ?? '',
        ];
    }

    /**
     * Remove caracteres especiais do número de telefone
     * 
     * @param string $phoneNumber
     * @return string
     */
    protected function sanitizePhoneNumber(string $phoneNumber): string
    {
        if (empty($phoneNumber)) {
            return '';
        }

        return preg_replace('/[\s\-\(\)\.\+]/', '', $phoneNumber);
    }
}


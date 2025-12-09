<?php

namespace App\Support;

use App\Models\AppConfig;

/**
 * Helper AppConfigHelper
 * 
 * Facilita acesso às configurações de apps do banco de dados.
 * 
 * @package App\Support
 */
class AppConfigHelper
{
    /**
     * Retorna credencial de uma app
     * 
     * @param string $appName
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $appName, string $key, $default = null)
    {
        $config = AppConfig::getConfig($appName);
        
        if (!$config) {
            return $default;
        }

        return $config->getCredential($key, $default);
    }

    /**
     * Retorna todas as credenciais de uma app
     * 
     * @param string $appName
     * @return array
     */
    public static function getAll(string $appName): array
    {
        $config = AppConfig::getConfig($appName);
        
        return $config ? $config->credentials : [];
    }

    /**
     * Verifica se uma app está configurada
     * 
     * @param string $appName
     * @return bool
     */
    public static function isConfigured(string $appName): bool
    {
        return AppConfig::isConfigured($appName);
    }

    /**
     * Retorna configuração completa de uma app
     * 
     * @param string $appName
     * @return AppConfig|null
     */
    public static function getConfig(string $appName): ?AppConfig
    {
        return AppConfig::getConfig($appName);
    }
}


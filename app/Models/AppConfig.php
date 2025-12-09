<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model AppConfig
 * 
 * Armazena configurações de aplicações externas (Instagram, WhatsApp, etc).
 * Gerenciado pelo SuperAdmin.
 * 
 * @package App\Models
 */
class AppConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'app_name',
        'display_name',
        'credentials',
        'settings',
        'is_active',
        'description',
    ];

    protected $casts = [
        'credentials' => 'array',
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Retorna credencial específica
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getCredential(string $key, $default = null)
    {
        return $this->credentials[$key] ?? $default;
    }

    /**
     * Verifica se a app está configurada e ativa
     * 
     * @param string $appName
     * @return bool
     */
    public static function isConfigured(string $appName): bool
    {
        $config = static::where('app_name', $appName)
            ->where('is_active', true)
            ->first();

        if (!$config) {
            return false;
        }

        // Verifica se tem credenciais mínimas
        $required = static::getRequiredCredentials($appName);
        foreach ($required as $key) {
            $value = $config->getCredential($key);
            // Verifica se está vazio, null ou apenas espaços
            if (empty($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Retorna credenciais necessárias por app
     * 
     * @param string $appName
     * @return array
     */
    public static function getRequiredCredentials(string $appName): array
    {
        return match ($appName) {
            'instagram' => ['app_id', 'app_secret'],
            'whatsapp' => ['app_id', 'app_secret'],
            'facebook' => ['app_id', 'app_secret'],
            default => [],
        };
    }

    /**
     * Busca configuração de uma app
     * 
     * @param string $appName
     * @return self|null
     */
    public static function getConfig(string $appName): ?self
    {
        return static::where('app_name', $appName)
            ->where('is_active', true)
            ->first();
    }
}


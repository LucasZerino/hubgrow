<?php

namespace App\Models;

use App\Models\Concerns\HasAccountScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Model Attachment
 * 
 * Representa um anexo de mensagem (imagem, vídeo, áudio, arquivo, localização, etc.).
 * Similar ao Chatwoot: Attachment
 * 
 * @package App\Models
 */
class Attachment extends Model
{
    use HasFactory;

    /**
     * Tipos de arquivo
     */
    public const FILE_TYPE_IMAGE = 0;
    public const FILE_TYPE_AUDIO = 1;
    public const FILE_TYPE_VIDEO = 2;
    public const FILE_TYPE_FILE = 3;
    public const FILE_TYPE_LOCATION = 4;
    public const FILE_TYPE_FALLBACK = 5;
    public const FILE_TYPE_SHARE = 6;
    public const FILE_TYPE_STORY_MENTION = 7;
    public const FILE_TYPE_CONTACT = 8;
    public const FILE_TYPE_IG_REEL = 9;

    protected $fillable = [
        'account_id',
        'message_id',
        'file_type',
        'external_url',
        'coordinates_lat',
        'coordinates_long',
        'fallback_title',
        'extension',
        'meta',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'file_metadata',
    ];

    protected $casts = [
        'meta' => 'array',
        'file_metadata' => 'array',
        'coordinates_lat' => 'double',
        'coordinates_long' => 'double',
        'file_size' => 'integer',
        'file_type' => 'integer',
    ];

    /**
     * Acessores para serialização JSON
     * 
     * @var array
     */
    protected $appends = [
        'file_type_name',
        'file_url',
        'download_url',
        'thumb_url',
    ];

    /**
     * Boot do model - aplica global scope
     */
    protected static function booted(): void
    {
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
     * Relacionamento com Message
     * 
     * @return BelongsTo
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Verifica se é uma imagem
     * 
     * @return bool
     */
    public function isImage(): bool
    {
        return $this->file_type === self::FILE_TYPE_IMAGE;
    }

    /**
     * Verifica se é um áudio
     * 
     * @return bool
     */
    public function isAudio(): bool
    {
        return $this->file_type === self::FILE_TYPE_AUDIO;
    }

    /**
     * Verifica se é um vídeo
     * 
     * @return bool
     */
    public function isVideo(): bool
    {
        return $this->file_type === self::FILE_TYPE_VIDEO;
    }

    /**
     * Verifica se é uma localização
     * 
     * @return bool
     */
    public function isLocation(): bool
    {
        return $this->file_type === self::FILE_TYPE_LOCATION;
    }

    /**
     * Verifica se tem arquivo anexado
     * 
     * @return bool
     */
    public function hasAttachedFile(): bool
    {
        return in_array($this->file_type, [
            self::FILE_TYPE_IMAGE,
            self::FILE_TYPE_AUDIO,
            self::FILE_TYPE_VIDEO,
            self::FILE_TYPE_FILE,
        ]);
    }

    /**
     * Retorna URL do arquivo
     * Similar ao Chatwoot: file_url
     * 
     * @return string
     */
    public function fileUrl(): string
    {
        // Para mensagens recebidas do Instagram, usa external_url
        if ($this->message && $this->message->inbox && $this->isIncomingMessage() && $this->external_url) {
            return $this->external_url;
        }

        // Se tem arquivo no storage, gera URL pública
        if ($this->file_path) {
            // Tenta MinIO/S3 primeiro (se configurado)
            $defaultDisk = env('FILESYSTEM_DISK', 'local');
            if ($defaultDisk === 's3') {
                // Não verifica existência para evitar erro de conexão ao MinIO
                // Se o arquivo foi salvo, a URL deve estar correta
                return $this->buildMinIOUrl($this->file_path);
            }
            
            // Fallback: tenta disco público
            // Para disco 'public', usa asset() ou constrói URL manualmente
            if (Storage::disk('public')->exists($this->file_path)) {
                // Constrói URL usando a configuração do disco 'public'
                $publicUrl = config('filesystems.disks.public.url', config('app.url') . '/storage');
                $url = rtrim($publicUrl, '/') . '/' . ltrim($this->file_path, '/');
                return $url;
            }
            
            // Último fallback: disco padrão (local)
            if (Storage::exists($this->file_path)) {
                $url = Storage::url($this->file_path);
                // Se a URL não começa com http, adiciona o domínio
                if (!str_starts_with($url, 'http')) {
                    $url = config('app.url') . $url;
                }
                return $url;
            }
            
            // Log para debug se não encontrou o arquivo
            error_log('[ATTACHMENT] Arquivo não encontrado no storage: ' . $this->file_path);
            error_log('[ATTACHMENT] Disk: ' . $defaultDisk);
            error_log('[ATTACHMENT] Existe em public? ' . (Storage::disk('public')->exists($this->file_path) ? 'SIM' : 'NÃO'));
            error_log('[ATTACHMENT] Existe em default? ' . (Storage::exists($this->file_path) ? 'SIM' : 'NÃO'));
        }

        return '';
    }

    /**
     * Retorna URL de download direto
     * Similar ao Chatwoot: download_url
     * 
     * IMPORTANTE: Retorna URL pública que o Instagram pode acessar
     * Similar ao Chatwoot: file.blob.url (ActiveStorage gera URL pública do S3)
     * 
     * @return string
     */
    public function downloadUrl(): string
    {
        // Para mensagens recebidas do Instagram, usa external_url
        if ($this->message && $this->message->inbox && $this->isIncomingMessage() && $this->external_url) {
            error_log('[ATTACHMENT] downloadUrl: Usando external_url para mensagem recebida');
            return $this->external_url;
        }

        // Se tem arquivo no storage, gera URL pública através do backend
        // Isso permite que o Instagram acesse os arquivos mesmo que o MinIO seja localhost
        if ($this->file_path && $this->id) {
            // Usa a rota do backend para servir o arquivo
            // Isso funciona mesmo se o MinIO estiver em localhost, pois o backend tem URL pública
            $backendUrl = config('app.url');
            $url = rtrim($backendUrl, '/') . '/api/attachments/' . $this->id;
            error_log('[ATTACHMENT] downloadUrl: Gerando URL do backend - ' . $url);
            return $url;
        } else {
            if (!$this->file_path) {
                error_log('[ATTACHMENT] downloadUrl: file_path está vazio para attachment ID: ' . ($this->id ?: 'SEM ID'));
            }
            if (!$this->id) {
                error_log('[ATTACHMENT] downloadUrl: attachment ID está vazio');
            }
        }

        error_log('[ATTACHMENT] downloadUrl: Retornando string vazia');
        return '';
    }
    
    /**
     * Constrói URL pública do MinIO
     * MinIO com path-style: http://endpoint/bucket/path/to/file
     * 
     * IMPORTANTE: Usa AWS_URL (URL pública) em vez de AWS_ENDPOINT (endpoint interno)
     * - AWS_ENDPOINT: usado pelo Laravel para conectar ao MinIO (pode ser minio:9000 no Docker)
     * - AWS_URL: URL pública acessível pelo frontend (deve ser localhost:9000)
     * 
     * @param string $filePath
     * @return string
     */
    protected function buildMinIOUrl(string $filePath): string
    {
        // Usa AWS_URL (URL pública) em vez de AWS_ENDPOINT (endpoint interno)
        // AWS_URL é a URL que o frontend/navegador usa para acessar o MinIO
        $publicUrl = env('AWS_URL', env('MINIO_URL', 'http://localhost:9000'));
        $bucket = env('AWS_BUCKET', env('MINIO_BUCKET', 'hubphp'));
        
        // Remove barra inicial do path se houver
        $filePath = ltrim($filePath, '/');
        
        // Constrói URL no formato: http://public-url/bucket/path
        return rtrim($publicUrl, '/') . '/' . $bucket . '/' . $filePath;
    }

    /**
     * Retorna URL da miniatura (para imagens)
     * Similar ao Chatwoot: thumb_url
     * 
     * @return string
     */
    public function thumbUrl(): string
    {
        if (!$this->isImage()) {
            return '';
        }

        // Para mensagens recebidas do Instagram, usa external_url como thumb
        if ($this->message && $this->message->inbox && $this->isIncomingMessage() && $this->external_url) {
            return $this->external_url;
        }

        // Se tem arquivo no storage, retorna URL normal (pode ser implementado resize depois)
        if ($this->file_path && Storage::exists($this->file_path)) {
            return Storage::url($this->file_path);
        }

        return '';
    }

    /**
     * Verifica se é mensagem recebida
     * 
     * @return bool
     */
    protected function isIncomingMessage(): bool
    {
        return $this->message && $this->message->message_type === Message::TYPE_INCOMING;
    }

    /**
     * Retorna dados para push de evento
     * Similar ao Chatwoot: push_event_data
     * 
     * @return array
     */
    public function pushEventData(): array
    {
        if (!$this->file_type) {
            return [];
        }

        $baseData = [
            'id' => $this->id,
            'message_id' => $this->message_id,
            'file_type' => $this->getFileTypeName(),
            'account_id' => $this->account_id,
        ];

        return array_merge($baseData, $this->getMetadataForFileType());
    }

    /**
     * Retorna metadados baseado no tipo de arquivo
     * Similar ao Chatwoot: metadata_for_file_type
     * 
     * @return array
     */
    protected function getMetadataForFileType(): array
    {
        return match ($this->file_type) {
            self::FILE_TYPE_LOCATION => $this->getLocationMetadata(),
            self::FILE_TYPE_FALLBACK => $this->getFallbackData(),
            self::FILE_TYPE_CONTACT => $this->getContactMetadata(),
            self::FILE_TYPE_AUDIO => $this->getAudioMetadata(),
            default => $this->getFileMetadata(),
        };
    }

    /**
     * Metadados para arquivos (image, video, file)
     * Similar ao Chatwoot: file_metadata
     * 
     * @return array
     */
    protected function getFileMetadata(): array
    {
        $metadata = [
            'extension' => $this->extension,
            'data_url' => $this->fileUrl(),
            'thumb_url' => $this->thumbUrl(),
        ];

        if ($this->file_size) {
            $metadata['file_size'] = $this->file_size;
        }

        if ($this->file_metadata) {
            if (isset($this->file_metadata['width'])) {
                $metadata['width'] = $this->file_metadata['width'];
            }
            if (isset($this->file_metadata['height'])) {
                $metadata['height'] = $this->file_metadata['height'];
            }
        }

        // Para mensagens recebidas do Instagram, usa external_url
        if ($this->message && $this->message->inbox && $this->isIncomingMessage() && $this->external_url) {
            $metadata['data_url'] = $this->external_url;
            $metadata['download_url'] = $this->external_url;
            $metadata['thumb_url'] = $this->external_url;
        } else {
            // Adiciona download_url para anexos enviados
            $metadata['download_url'] = $this->downloadUrl();
        }

        return $metadata;
    }

    /**
     * Metadados para localização
     * Similar ao Chatwoot: location_metadata
     * 
     * @return array
     */
    protected function getLocationMetadata(): array
    {
        return [
            'coordinates_lat' => $this->coordinates_lat,
            'coordinates_long' => $this->coordinates_long,
            'fallback_title' => $this->fallback_title,
            'data_url' => $this->external_url,
        ];
    }

    /**
     * Metadados para fallback
     * Similar ao Chatwoot: fallback_data
     * 
     * @return array
     */
    protected function getFallbackData(): array
    {
        return [
            'fallback_title' => $this->fallback_title,
            'data_url' => $this->external_url,
        ];
    }

    /**
     * Metadados para contato
     * Similar ao Chatwoot: contact_metadata
     * 
     * @return array
     */
    protected function getContactMetadata(): array
    {
        return [
            'fallback_title' => $this->fallback_title,
            'meta' => $this->meta ?? [],
        ];
    }

    /**
     * Metadados para áudio
     * Similar ao Chatwoot: audio_metadata
     * 
     * @return array
     */
    protected function getAudioMetadata(): array
    {
        $audioData = array_merge($this->getFileMetadata(), [
            'transcribed_text' => $this->meta['transcribed_text'] ?? '',
        ]);

        return $audioData;
    }

    /**
     * Retorna nome do tipo de arquivo
     * 
     * @return string
     */
    protected function getFileTypeName(): string
    {
        return match ($this->file_type) {
            self::FILE_TYPE_IMAGE => 'image',
            self::FILE_TYPE_AUDIO => 'audio',
            self::FILE_TYPE_VIDEO => 'video',
            self::FILE_TYPE_FILE => 'file',
            self::FILE_TYPE_LOCATION => 'location',
            self::FILE_TYPE_FALLBACK => 'fallback',
            self::FILE_TYPE_SHARE => 'share',
            self::FILE_TYPE_STORY_MENTION => 'story_mention',
            self::FILE_TYPE_CONTACT => 'contact',
            self::FILE_TYPE_IG_REEL => 'ig_reel',
            default => 'file',
        };
    }

    /**
     * Accessor para file_type_name
     * 
     * @return string
     */
    public function getFileTypeNameAttribute(): string
    {
        return $this->getFileTypeName();
    }

    /**
     * Accessor para file_url
     * 
     * @return string
     */
    public function getFileUrlAttribute(): string
    {
        return $this->fileUrl();
    }

    /**
     * Accessor para download_url
     * 
     * @return string
     */
    public function getDownloadUrlAttribute(): string
    {
        return $this->downloadUrl();
    }

    /**
     * Accessor para thumb_url
     * 
     * @return string
     */
    public function getThumbUrlAttribute(): string
    {
        return $this->thumbUrl();
    }

    /**
     * Override toArray para incluir metadados completos
     * Similar ao Chatwoot: push_event_data
     * 
     * @return array
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        
        // Adiciona metadados específicos do tipo de arquivo
        $metadata = $this->getMetadataForFileType();
        
        return array_merge($array, $metadata);
    }
}


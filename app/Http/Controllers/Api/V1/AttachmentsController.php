<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

/**
 * Controller para servir arquivos de anexos
 * Faz proxy dos arquivos do MinIO/S3 para URLs públicas acessíveis pelo Instagram
 */
class AttachmentsController extends \App\Http\Controllers\Controller
{
    /**
     * Serve um arquivo de anexo
     * Rota pública para permitir que o Instagram acesse os arquivos
     * 
     * @param int $attachmentId ID do anexo
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function show(int $attachmentId)
    {
        try {
            // Usa withoutGlobalScopes para permitir acesso público (sem autenticação)
            // Necessário para que o Facebook/Instagram consigam baixar o arquivo
            $attachment = Attachment::withoutGlobalScopes()->findOrFail($attachmentId);
            
            // Se não tem file_path, retorna erro
            if (!$attachment->file_path) {
                Log::warning('[ATTACHMENTS CONTROLLER] Attachment sem file_path', [
                    'attachment_id' => $attachmentId,
                ]);
                return response()->json(['error' => 'File not found'], 404);
            }
            
            // Determina o disco (s3 ou public)
            $defaultDisk = env('FILESYSTEM_DISK', 'local');
            $disk = $defaultDisk === 's3' ? 's3' : 'public';
            
            // Verifica se o arquivo existe no storage
            if (!Storage::disk($disk)->exists($attachment->file_path)) {
                Log::warning('[ATTACHMENTS CONTROLLER] Arquivo não encontrado no storage', [
                    'attachment_id' => $attachmentId,
                    'file_path' => $attachment->file_path,
                    'disk' => $disk,
                ]);
                return response()->json(['error' => 'File not found in storage'], 404);
            }
            
            // Obtém o conteúdo do arquivo
            $fileContent = Storage::disk($disk)->get($attachment->file_path);
            
            // Obtém o MIME type
            // Tenta usar o mime_type do attachment, senão detecta pela extensão
            $mimeType = $attachment->mime_type;
            if (!$mimeType) {
                $extension = $attachment->extension ?: pathinfo($attachment->file_path, PATHINFO_EXTENSION);
                $mimeType = $this->getMimeTypeFromExtension($extension);
            }
            $mimeType = $mimeType ?: 'application/octet-stream';
            
            // Obtém o nome do arquivo
            $fileName = $attachment->file_name ?: basename($attachment->file_path);
            
            // Retorna o arquivo com headers apropriados
            return response($fileContent, 200)
                ->header('Content-Type', $mimeType)
                ->header('Content-Disposition', 'inline; filename="' . $fileName . '"')
                ->header('Cache-Control', 'public, max-age=31536000') // Cache por 1 ano
                ->header('Access-Control-Allow-Origin', '*'); // Permite CORS para Instagram
                
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('[ATTACHMENTS CONTROLLER] Attachment não encontrado', [
                'attachment_id' => $attachmentId,
            ]);
            return response()->json(['error' => 'Attachment not found'], 404);
        } catch (\Exception $e) {
            Log::error('[ATTACHMENTS CONTROLLER] Erro ao servir arquivo', [
                'attachment_id' => $attachmentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }
    
    /**
     * Detecta MIME type baseado na extensão do arquivo
     * 
     * @param string $extension
     * @return string
     */
    protected function getMimeTypeFromExtension(string $extension): string
    {
        $mimeTypes = [
            // Áudio
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'webm' => 'audio/webm',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'opus' => 'audio/opus',
            
            // Vídeo
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska',
            'flv' => 'video/x-flv',
            
            // Imagem
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'bmp' => 'image/bmp',
            
            // Documentos
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        
        $extension = strtolower($extension);
        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}


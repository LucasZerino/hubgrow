<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Controller HealthController
 * 
 * Retorna o status de saúde do sistema e informações básicas.
 * Útil para monitoramento e verificação de disponibilidade.
 * 
 * @package App\Http\Controllers
 */
class HealthController extends Controller
{
    /**
     * Retorna o status de saúde do sistema
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $status = 'ok';
        $checks = [];

        // Verifica conexão com banco de dados
        try {
            DB::connection()->getPdo();
            $checks['database'] = [
                'status' => 'ok',
                'message' => 'Conexão com banco de dados estabelecida',
            ];
        } catch (\Exception $e) {
            $status = 'error';
            $checks['database'] = [
                'status' => 'error',
                'message' => 'Erro ao conectar com banco de dados: ' . $e->getMessage(),
            ];
        }

        // Verifica conexão com Redis (se configurado)
        try {
            if (config('database.redis.default.host')) {
                Redis::connection()->ping();
                $checks['redis'] = [
                    'status' => 'ok',
                    'message' => 'Conexão com Redis estabelecida',
                ];
            } else {
                $checks['redis'] = [
                    'status' => 'skipped',
                    'message' => 'Redis não configurado',
                ];
            }
        } catch (\Exception $e) {
            $checks['redis'] = [
                'status' => 'error',
                'message' => 'Erro ao conectar com Redis: ' . $e->getMessage(),
            ];
        }

        return response()->json([
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'time' => now()->format('Y-m-d H:i:s'),
            'timezone' => config('app.timezone'),
            'environment' => app()->environment(),
            'version' => '1.0.0',
            'app_url' => config('app.url'),
            'frontend_url' => config('app.frontend_url'),
            'checks' => $checks,
        ], $status === 'ok' ? 200 : 503);
    }
}


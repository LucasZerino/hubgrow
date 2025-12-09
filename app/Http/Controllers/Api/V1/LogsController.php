<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Accounts\BaseController;
use App\Models\AppLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller LogsController
 * 
 * API para consultar logs armazenados no banco de dados.
 * 
 * @package App\Http\Controllers\Api\V1
 */
class LogsController extends BaseController
{
    /**
     * Lista logs com filtros
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = AppLog::query()
            ->forAccount($this->account->id)
            ->orderBy('created_at', 'desc');

        // Filtros
        if ($request->has('level')) {
            $query->level($request->input('level'));
        }

        if ($request->has('channel')) {
            $query->channel($request->input('channel'));
        }

        if ($request->has('search')) {
            $query->search($request->input('search'));
        }

        if ($request->has('hours')) {
            $query->lastHours((int) $request->input('hours'));
        }

        // Paginação
        $perPage = min((int) $request->input('per_page', 50), 100);
        $logs = $query->paginate($perPage);

        return response()->json($logs);
    }

    /**
     * Mostra um log específico
     * 
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $log = AppLog::forAccount($this->account->id)
            ->findOrFail($id);

        return response()->json($log);
    }

    /**
     * Estatísticas de logs
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function stats(Request $request): JsonResponse
    {
        $hours = (int) $request->input('hours', 24);

        $query = AppLog::query()
            ->forAccount($this->account->id)
            ->lastHours($hours);

        $stats = [
            'total' => $query->count(),
            'by_level' => $query->selectRaw('level, COUNT(*) as count')
                ->groupBy('level')
                ->pluck('count', 'level'),
            'by_channel' => $query->selectRaw('channel, COUNT(*) as count')
                ->whereNotNull('channel')
                ->groupBy('channel')
                ->pluck('count', 'channel'),
            'errors_last_24h' => $query->level('error')->count(),
        ];

        return response()->json($stats);
    }
}


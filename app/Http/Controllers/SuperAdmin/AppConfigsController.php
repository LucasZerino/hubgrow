<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AppConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller AppConfigsController (SuperAdmin)
 * 
 * Gerencia configurações de aplicações externas (Instagram, WhatsApp, etc).
 * Apenas para super admins.
 * 
 * @package App\Http\Controllers\SuperAdmin
 */
class AppConfigsController extends Controller
{
    /**
     * Lista todas as configurações
     * 
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $configs = AppConfig::all();

        return response()->json($configs);
    }

    /**
     * Mostra uma configuração específica
     * 
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $config = AppConfig::findOrFail($id);

        return response()->json($config);
    }

    /**
     * Cria uma nova configuração
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'app_name' => 'required|string|max:50|unique:app_configs,app_name',
            'display_name' => 'required|string|max:100',
            'credentials' => 'required|array',
            'settings' => 'nullable|array',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string',
        ]);

        $config = AppConfig::create($validated);

        return response()->json($config, 201);
    }

    /**
     * Atualiza uma configuração
     * 
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $config = AppConfig::findOrFail($id);

        $validated = $request->validate([
            'display_name' => 'sometimes|string|max:100',
            'credentials' => 'sometimes|array',
            'settings' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
            'description' => 'nullable|string',
        ]);

        $config->update($validated);

        return response()->json($config);
    }

    /**
     * Deleta uma configuração
     * 
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        $config = AppConfig::findOrFail($id);
        $config->delete();

        return response()->json(['message' => 'Configuração deletada com sucesso']);
    }

    /**
     * Busca configuração por app_name
     * 
     * @param string $appName
     * @return JsonResponse
     */
    public function getByAppName(string $appName): JsonResponse
    {
        $config = AppConfig::where('app_name', $appName)->first();

        if (!$config) {
            return response()->json(['error' => 'Configuração não encontrada'], 404);
        }

        return response()->json($config);
    }
}


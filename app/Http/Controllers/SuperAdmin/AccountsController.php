<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller AccountsController (SuperAdmin)
 * 
 * Gerencia accounts do sistema (apenas para super admins).
 * Permite criar, editar, deletar accounts e gerenciar limites.
 * 
 * @package App\Http\Controllers\SuperAdmin
 */
class AccountsController extends Controller
{
    /**
     * Lista todas as accounts
     * 
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $accounts = Account::withCount(['inboxes', 'contacts', 'conversations'])
            ->get();

        return response()->json($accounts);
    }

    /**
     * Mostra uma account específica
     * 
     * @param Account $account
     * @return JsonResponse
     */
    public function show(Account $account): JsonResponse
    {
        $account->loadCount(['inboxes', 'contacts', 'conversations']);
        
        return response()->json($account);
    }

    /**
     * Cria uma nova account
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'nullable|string|max:100',
            'support_email' => 'nullable|email|max:100',
            'locale' => 'nullable|integer',
            'status' => 'nullable|integer',
        ]);

        $account = Account::create($validated);

        return response()->json($account, 201);
    }

    /**
     * Atualiza uma account
     * 
     * @param Request $request
     * @param Account $account
     * @return JsonResponse
     */
    public function update(Request $request, Account $account): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'domain' => 'nullable|string|max:100',
            'support_email' => 'nullable|email|max:100',
            'locale' => 'nullable|integer',
            'status' => 'nullable|integer',
        ]);

        $account->update($validated);

        return response()->json($account);
    }

    /**
     * Deleta uma account
     * 
     * @param Account $account
     * @return JsonResponse
     */
    public function destroy(Account $account): JsonResponse
    {
        $account->delete();

        return response()->json(['message' => 'Account deletada com sucesso']);
    }

    /**
     * Atualiza limites de uma account
     * 
     * @param Request $request
     * @param Account $account
     * @return JsonResponse
     */
    public function updateLimits(Request $request, Account $account): JsonResponse
    {
        $validated = $request->validate([
            'limits' => 'required|array',
            'limits.inboxes' => 'nullable|integer|min:-1',
            'limits.agents' => 'nullable|integer|min:-1',
            'limits.whatsapp_channels' => 'nullable|integer|min:-1',
            'limits.instagram_channels' => 'nullable|integer|min:-1',
            'limits.facebook_channels' => 'nullable|integer|min:-1',
            'limits.webwidget_channels' => 'nullable|integer|min:-1',
        ]);

        $limits = $account->limits ?? [];
        
        foreach ($validated['limits'] as $resource => $limit) {
            if ($limit !== null) {
                $limits[$resource] = $limit === -1 ? PHP_INT_MAX : $limit;
            }
        }

        $account->limits = $limits;
        $account->save();

        return response()->json([
            'account' => $account,
            'limits' => $account->limits,
        ]);
    }

    /**
     * Retorna limites de uma account
     * 
     * @param Account $account
     * @return JsonResponse
     */
    public function getLimits(Account $account): JsonResponse
    {
        $limits = [];
        $resources = ['inboxes', 'agents', 'whatsapp_channels', 'instagram_channels', 'facebook_channels', 'webwidget_channels'];

        foreach ($resources as $resource) {
            $limits[$resource] = $account->getResourceUsage($resource);
        }

        return response()->json([
            'account_id' => $account->id,
            'limits' => $limits,
        ]);
    }
}

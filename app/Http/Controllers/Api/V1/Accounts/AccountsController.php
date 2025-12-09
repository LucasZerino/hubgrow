<?php

namespace App\Http\Controllers\Api\V1\Accounts;

use App\Support\Current;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller AccountsController
 * 
 * Gerencia a própria account (mostrar e atualizar).
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Http\Controllers\Api\V1\Accounts
 */
class AccountsController extends BaseController
{
    /**
     * Mostra a account atual
     * 
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        $account = Current::account();
        $account->loadCount(['inboxes', 'contacts', 'conversations', 'messages']);

        return response()->json($account);
    }

    /**
     * Atualiza a account atual
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $account = Current::account();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'domain' => 'nullable|string|max:100',
            'support_email' => 'nullable|email|max:100',
            'locale' => 'nullable|integer',
            'auto_resolve_duration' => 'nullable|integer',
            'custom_attributes' => 'nullable|array',
            'settings' => 'nullable|array',
        ]);

        $account->update($validated);

        return response()->json($account);
    }
}

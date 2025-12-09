<?php

namespace App\Http\Middleware;

use App\Support\Current;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware EnsureAccountAccess
 * 
 * Garante que o usuário tenha acesso à account especificada na rota.
 * Define Current::account() e Current::user() para multi-tenancy.
 * 
 * @package App\Http\Middleware
 */
class EnsureAccountAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Log::info('[ENSURE_ACCOUNT_ACCESS] ========== START ==========', [
            'path' => $request->path(),
            'full_url' => $request->fullUrl(),
            'method' => $request->method(),
            'user_id' => $request->user()?->id,
            'account_id_param' => $request->route('account_id'),
            'has_auth_header' => $request->hasHeader('Authorization'),
        ]);

        $user = $request->user();
        $accountId = $request->route('account_id');

        // Verifica se o usuário está autenticado
        if (!$user) {
            Log::warning('[ENSURE_ACCOUNT_ACCESS] Usuário não autenticado', [
                'has_auth_header' => $request->hasHeader('Authorization'),
                'bearer_token' => $request->bearerToken() ? 'present' : 'missing',
            ]);
            return response()->json([
                'error' => 'Não autenticado',
                'message' => 'Token de autenticação inválido ou ausente.',
            ], 401);
        }

        if (!$accountId) {
            Log::warning('[ENSURE_ACCOUNT_ACCESS] Account ID não fornecido');
            return response()->json(['error' => 'Account ID não fornecido'], 400);
        }

        // Super admin tem acesso a todas as accounts
        if ($user->isSuperAdmin()) {
            $account = \App\Models\Account::find($accountId);
        } else {
            // Verifica se usuário tem acesso à account
            if (!$user->hasAccessToAccount($accountId)) {
                Log::warning('[ENSURE_ACCOUNT_ACCESS] Usuário sem acesso', [
                    'user_id' => $user->id,
                    'account_id' => $accountId,
                ]);
                return response()->json([
                    'error' => 'Você não tem acesso a esta conta.',
                ], 403);
            }

            $account = \App\Models\Account::find($accountId);
        }

        if (!$account || !$account->isActive()) {
            Log::warning('[ENSURE_ACCOUNT_ACCESS] Account não encontrada ou suspensa', [
                'account_id' => $accountId,
                'found' => $account !== null,
                'active' => $account?->isActive(),
            ]);
            return response()->json([
                'error' => 'Conta não encontrada ou suspensa.',
            ], 404);
        }

        // Define contexto para multi-tenancy
        Log::info('[ENSURE_ACCOUNT_ACCESS] Definindo Current::account()', [
            'account_id' => $account->id,
            'account_name' => $account->name,
            'before_set_account' => Current::account()?->id,
        ]);
        
        Current::setAccount($account);
        Current::setUser($user);
        
        // Verifica se foi definido corretamente
        $accountAfterSet = Current::account();
        Log::info('[ENSURE_ACCOUNT_ACCESS] Current::account() após definir', [
            'account_set' => $accountAfterSet !== null,
            'account_id' => $accountAfterSet?->id,
            'account_name' => $accountAfterSet?->name,
            'matches' => $accountAfterSet?->id === $account->id,
        ]);

        Log::info('[ENSURE_ACCOUNT_ACCESS] ========== SUCCESS ==========', [
            'account_id' => $account->id,
            'account_name' => $account->name,
            'current_account_verified' => Current::account()?->id === $account->id,
        ]);

        Log::info('[ENSURE_ACCOUNT_ACCESS] Calling next middleware/controller');
        $response = $next($request);
        Log::info('[ENSURE_ACCOUNT_ACCESS] Next middleware/controller returned', [
            'status' => $response->getStatusCode(),
            'current_account_still_set' => Current::account()?->id === $account->id,
        ]);

        return $response;
    }
}


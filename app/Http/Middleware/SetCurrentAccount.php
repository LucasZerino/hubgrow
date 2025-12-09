<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Support\Current;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware SetCurrentAccount
 * 
 * Define o account atual no contexto (Current) baseado no account_id da rota.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Http\Middleware
 */
class SetCurrentAccount
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $accountId = $request->route('account_id') ?? $request->route('account');
        $user = $request->user();

        if ($accountId) {
            $account = Account::findOrFail($accountId);

            // Verifica se a conta está ativa
            if (!$account->isActive()) {
                return response()->json([
                    'error' => 'Account is suspended'
                ], 403);
            }

            // Verifica acesso (se usuário autenticado)
            if ($user && !$user->isSuperAdmin() && !$user->hasAccessToAccount($accountId)) {
                return response()->json(['error' => 'Você não tem acesso a esta conta.'], 403);
            }

            Current::setAccount($account);
            if ($user) {
                Current::setUser($user);
            }
        }

        return $next($request);
    }
}

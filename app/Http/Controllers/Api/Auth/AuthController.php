<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use App\Support\Current;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Controller AuthController
 * 
 * Gerencia autenticação de usuários (login, registro, logout).
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Http\Controllers\Api\Auth
 */
class AuthController extends Controller
{
    /**
     * Login do usuário
     * 
     * @param Request $request
     * @return JsonResponse
     * @throws ValidationException
     */
    public function login(Request $request): JsonResponse
    {
        \Illuminate\Support\Facades\Log::info('[AUTH] Login request received', [
            'email' => $request->email,
            'origin' => $request->header('Origin'),
            'user_agent' => $request->header('User-Agent'),
        ]);

        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();
        
        \Illuminate\Support\Facades\Log::info('[AUTH] User lookup', [
            'user_found' => $user !== null,
            'user_id' => $user?->id,
        ]);

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['As credenciais fornecidas estão incorretas.'],
            ]);
        }

        // Cria token Sanctum
        $token = $user->createToken('auth-token')->plainTextToken;

        // Busca primeira account do usuário (ou null se super admin)
        $account = null;
        if (!$user->isSuperAdmin()) {
            $accountUser = $user->accountUsers()->first();
            $account = $accountUser?->account;
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_super_admin' => $user->isSuperAdmin(),
            ],
            'account' => $account ? [
                'id' => $account->id,
                'name' => $account->name,
            ] : null,
            'token' => $token,
        ]);
    }

    /**
     * Registro de novo usuário
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'account_name' => 'required|string|max:255',
        ]);

        // Cria usuário
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'is_super_admin' => false,
        ]);

        // Cria account
        $account = Account::create([
            'name' => $request->account_name,
            'status' => Account::STATUS_ACTIVE,
        ]);

        // Vincula usuário à account como administrador
        AccountUser::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'role' => AccountUser::ROLE_ADMINISTRATOR,
        ]);

        // Cria token
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_super_admin' => false,
            ],
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
            ],
            'token' => $token,
        ], 201);
    }

    /**
     * Logout do usuário
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout realizado com sucesso']);
    }

    /**
     * Retorna usuário autenticado
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $account = null;

        if (!$user->isSuperAdmin() && Current::account()) {
            $account = Current::account();
        } elseif (!$user->isSuperAdmin()) {
            $accountUser = $user->accountUsers()->first();
            $account = $accountUser?->account;
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_super_admin' => $user->isSuperAdmin(),
            ],
            'account' => $account ? [
                'id' => $account->id,
                'name' => $account->name,
            ] : null,
        ]);
    }
}

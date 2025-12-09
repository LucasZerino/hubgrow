<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        \App\Providers\BroadcastServiceProvider::class,
        \App\Providers\MinIOServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Registra middlewares customizados
        $middleware->alias([
            'throttle.webhooks' => \App\Http\Middleware\ThrottleWebhooks::class,
            'traffic.logger' => \App\Http\Middleware\HttpTrafficLogger::class,
        ]);
        
        // Habilita CORS globalmente - aplica em TODAS as rotas (web e api)
        // Usa middleware customizado que funciona sem dependências externas
        // Mantém o middleware padrão do Laravel também (pode estar sendo usado internamente)
        $middleware->api(prepend: [
            \App\Http\Middleware\HandleCorsMiddleware::class,
            \App\Http\Middleware\HttpTrafficLogger::class,
        ]);
        
        $middleware->web(prepend: [
            \App\Http\Middleware\HandleCorsMiddleware::class,
        ]);
        
        // Broadcasting: autentica via token ANTES do BroadcastController processar
        // Isso garante que o resolveAuthenticatedUserUsing tenha um usuário autenticado
        $middleware->web(append: [
            \App\Http\Middleware\AuthenticateBroadcasting::class,
        ]);
        
        // Também aplica o middleware de autenticação para broadcasting nas rotas API
        $middleware->api(append: [
            \App\Http\Middleware\AuthenticateBroadcasting::class,
        ]);
        
        // Exclui rotas de API e broadcasting/auth da validação CSRF
        // APIs usam autenticação via token (Bearer), não CSRF
        $middleware->validateCsrfTokens(except: [
            'api/*',
            'broadcasting/auth',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handler customizado para exceções de autenticação (401)
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            // Se for uma requisição de API (prefixo api/)
            if ($request->is('api/*')) {
                \Illuminate\Support\Facades\Log::warning('[401 HANDLER] Erro de autenticação', [
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'full_url' => $request->fullUrl(),
                    'has_auth_header' => $request->hasHeader('Authorization'),
                    'bearer_token' => $request->bearerToken() ? 'present' : 'missing',
                    'message' => $e->getMessage(),
                ]);
                
                return response()->json([
                    'error' => 'Não autenticado',
                    'message' => 'Token de autenticação inválido ou ausente.',
                ], 401);
            }
            
            // Para outras rotas, usa o handler padrão
            return null;
        });
        
        // Handler customizado para erros de validação (422)
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, \Illuminate\Http\Request $request) {
            // Se for uma requisição de API (prefixo api/)
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => 'Erro de validação',
                    'message' => 'Os dados fornecidos são inválidos.',
                    'errors' => $e->errors(),
                ], 422);
            }
            
            // Para outras rotas, usa o handler padrão
            return null;
        });
        
        // Handler customizado para rotas não encontradas (404)
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request) {
            // Se for uma requisição de API (prefixo api/)
            if ($request->is('api/*')) {
                // IMPORTANTE: Se a rota existe mas não foi encontrada, pode ser problema de middleware
                // Verifica se a rota realmente existe
                $routeExists = \Illuminate\Support\Facades\Route::has('inboxes.show');
                
                if ($routeExists && $request->is('api/v1/accounts/*/inboxes/*')) {
                    // A rota existe mas não foi encontrada - provavelmente problema de middleware
                    // O middleware auth:sanctum pode estar bloqueando antes de chegar à rota
                    \Illuminate\Support\Facades\Log::error('[404 HANDLER] Rota existe mas não foi encontrada!', [
                        'path' => $request->path(),
                        'method' => $request->method(),
                        'route_name' => 'inboxes.show',
                        'user_id' => $request->user()?->id,
                        'has_auth_header' => $request->hasHeader('Authorization'),
                        'bearer_token' => $request->bearerToken() ? 'present' : 'missing',
                        'note' => 'Se o token for inválido, o middleware auth:sanctum bloqueia ANTES de encontrar a rota',
                    ]);
                    
                    // Se não há usuário autenticado, retorna 401 em vez de 404
                    if (!$request->user()) {
                        return response()->json([
                            'error' => 'Não autenticado',
                            'message' => 'Token de autenticação inválido ou ausente. A rota existe mas requer autenticação.',
                            'path' => $request->path(),
                            'method' => $request->method(),
                        ], 401);
                    }
                    
                    // Retorna erro mais específico
                    return response()->json([
                        'error' => 'Rota não encontrada',
                        'message' => 'A rota existe mas não foi encontrada. Verifique se o token de autenticação é válido.',
                        'path' => $request->path(),
                        'method' => $request->method(),
                        'route_exists' => true,
                    ], 404);
                }
                
                // Log para debug
                \Illuminate\Support\Facades\Log::warning('[404 HANDLER] Rota não encontrada', [
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'full_url' => $request->fullUrl(),
                    'user_id' => $request->user()?->id,
                    'has_auth_header' => $request->hasHeader('Authorization'),
                ]);
                
                return response()->json([
                    'error' => 'Rota não encontrada',
                    'message' => 'A rota solicitada não existe no sistema.',
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'available_routes' => [
                        'auth/login',
                        'auth/logout',
                        'auth/me',
                        'super-admin/accounts',
                        'super-admin/app-configs',
                        'v1/accounts/{account_id}/inboxes',
                        'v1/accounts/{account_id}/inboxes/{inbox_id}',
                        'v1/accounts/{account_id}/conversations',
                        'v1/accounts/{account_id}/conversations/{conversation_id}',
                        'v1/accounts/{account_id}/contacts',
                        'v1/accounts/{account_id}/contacts/{contact_id}',
                        'v1/accounts/{account_id}/messages',
                        'webhooks/whatsapp/{phone_number}',
                        'webhooks/instagram',
                        'webhooks/facebook',
                    ],
                ], 404);
            }
            
            // Para outras rotas, usa o handler padrão
            return null;
        });
        
        // Handler genérico para todas as outras exceções não tratadas (500)
        // Garante que rotas API sempre retornem JSON, mesmo para erros não esperados
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            // Se for uma requisição de API (prefixo api/)
            if ($request->is('api/*')) {
                // Log do erro completo
                \Illuminate\Support\Facades\Log::error('[500 HANDLER] Exceção não tratada em API', [
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'full_url' => $request->fullUrl(),
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                // Em produção, não expõe detalhes do erro
                $message = app()->environment('production') 
                    ? 'Ocorreu um erro interno do servidor.' 
                    : $e->getMessage();
                
                return response()->json([
                    'error' => 'Erro interno do servidor',
                    'message' => $message,
                ], 500);
            }
            
            // Para outras rotas, usa o handler padrão
            return null;
        });
    })->create();

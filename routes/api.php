<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Webhooks\WhatsAppController;
use App\Http\Controllers\Webhooks\InstagramController;
use App\Http\Controllers\Webhooks\FacebookController;
use App\Http\Middleware\EnsureAccountAccess;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\SetCurrentAccount;
use App\Http\Middleware\VerifyApiKey;
use App\Http\Middleware\VerifyWebsiteToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Models\Message;
use App\Services\Webhooks\WebhookDispatcher;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Rotas da API do sistema.
| Aplicam middlewares de multi-tenancy e autenticação.
|
*/

// Rota de health check (pública - não requer autenticação)
Route::get('health', [\App\Http\Controllers\HealthController::class, 'index'])->name('health');

// Rota para testar webhook payload (pública - sem autenticação para facilitar testes)
Route::get('/test-webhook-payload/{messageId}', function ($messageId) {
    $message = Message::find($messageId);
    
    if (!$message) {
        return response()->json(['error' => 'Message not found'], 404);
    }
    
    $payload = WebhookDispatcher::prepareMessagePayload($message, 'message_created');
    
    return response()->json($payload, 200, [], JSON_PRETTY_PRINT);
});

// Rotas de autenticação (públicas - não requerem autenticação)
// NOTA: Rotas de broadcasting são registradas automaticamente via channels: no bootstrap/app.php
// Não precisamos registrar manualmente aqui
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('me', [AuthController::class, 'me'])->middleware('auth:sanctum');
});

// Rota pública para servir arquivos de anexos (acessível pelo Instagram)
// Não requer autenticação para permitir que o Instagram acesse os arquivos
Route::get('attachments/{attachmentId}', [\App\Http\Controllers\Api\V1\AttachmentsController::class, 'show'])
    ->name('attachments.show')
    ->where('attachmentId', '[0-9]+');

// Rotas públicas (webhooks)
// Aplica rate limiting para prevenir sobrecarga (100 requests/minuto por IP/canal)
Route::prefix('webhooks')->middleware(['throttle.webhooks:100,1', 'traffic.logger'])->group(function () {
    // WhatsApp webhook
    Route::get('whatsapp/{phone_number}', [WhatsAppController::class, 'verify']);
    Route::post('whatsapp/{phone_number}', [WhatsAppController::class, 'processPayload']);
    
    // Instagram webhook
    Route::get('instagram', [InstagramController::class, 'verify']);
    Route::post('instagram', [InstagramController::class, 'events']);
    
    // Instagram OAuth callback (público - sem autenticação)
    // IMPORTANTE: Esta rota permite multi-tenant porque é sempre a mesma URL
    // No Meta, configure apenas: https://api.seudominio.com/api/webhooks/instagram/callback
    Route::get('instagram/callback', [
        \App\Http\Controllers\Webhooks\InstagramOAuthCallbackController::class,
        'handle'
    ])->name('instagram.oauth.callback');
    
    // Facebook webhook
    Route::get('facebook', [FacebookController::class, 'verify']);
    Route::post('facebook', [FacebookController::class, 'events']);
    
    // Facebook OAuth callback (público - sem autenticação)
    // IMPORTANTE: Esta rota permite multi-tenant porque é sempre a mesma URL
    // No Meta, configure apenas: https://api.seudominio.com/api/webhooks/facebook/callback
    Route::get('facebook/callback', [
        \App\Http\Controllers\Webhooks\FacebookOAuthCallbackController::class,
        'handle'
    ])->name('facebook.oauth.callback');
    
});

// Rotas públicas do Widget
Route::prefix('public/v1/widget')->group(function () {
    Route::get('config', [\App\Http\Controllers\Api\V1\Widget\ConfigController::class, 'index'])
        ->withoutMiddleware([VerifyWebsiteToken::class]);

    Route::resource('messages', \App\Http\Controllers\Api\V1\Widget\MessagesController::class, [
        'only' => ['index', 'create', 'update']
    ])->middleware([VerifyWebsiteToken::class]);
    
    Route::resource('conversations', \App\Http\Controllers\Api\V1\Widget\ConversationsController::class, [
        'only' => ['index', 'create']
    ])->middleware([VerifyWebsiteToken::class]);
    
    Route::post('conversations/update_last_seen', [
        \App\Http\Controllers\Api\V1\Widget\ConversationsController::class,
        'updateLastSeen'
    ])->middleware([VerifyWebsiteToken::class]);
    
    Route::get('conversations/toggle_status', [
        \App\Http\Controllers\Api\V1\Widget\ConversationsController::class,
        'toggleStatus'
    ])->middleware([VerifyWebsiteToken::class]);
});

// Rotas de Super Admin (apenas para super admins)
Route::prefix('super-admin')->middleware(['auth:sanctum', EnsureSuperAdmin::class])->group(function () {
    Route::apiResource('accounts', \App\Http\Controllers\SuperAdmin\AccountsController::class);
    
    // Gerenciar limites de accounts
    Route::put('accounts/{account}/limits', [\App\Http\Controllers\SuperAdmin\AccountsController::class, 'updateLimits']);
    Route::get('accounts/{account}/limits', [\App\Http\Controllers\SuperAdmin\AccountsController::class, 'getLimits']);
    
    // Gerenciar configurações de apps
    Route::apiResource('app-configs', \App\Http\Controllers\SuperAdmin\AppConfigsController::class);
    Route::get('app-configs/by-app/{appName}', [\App\Http\Controllers\SuperAdmin\AppConfigsController::class, 'getByAppName']);
});

// Rotas com API Key (para venda da API)
Route::middleware([VerifyApiKey::class])->group(function () {
    Route::prefix('v1')->group(function () {
        // Rotas da API pública
        // Exemplo: Route::get('inboxes', [InboxController::class, 'index']);
    });
});

// Rotas com Account ID (para dashboard/admin)
// Requer autenticação e verifica acesso à account
Route::middleware(['auth:sanctum', EnsureAccountAccess::class, 'traffic.logger'])->group(function () {
    Route::prefix('v1/accounts/{account_id}')->group(function () {
        // WhatsApp Routes
        Route::prefix('whatsapp')->group(function () {
            // Autorização (embedded signup)
            Route::post('authorization', [
                \App\Http\Controllers\Api\V1\Accounts\WhatsApp\AuthorizationsController::class,
                'create'
            ])->name('whatsapp.authorization');
            
            // Health check
            Route::get('health/{inbox_id}', [
                \App\Http\Controllers\Api\V1\Accounts\WhatsApp\HealthController::class,
                'show'
            ])->name('whatsapp.health');
            
            // Templates
            Route::get('templates/{inbox_id}', [
                \App\Http\Controllers\Api\V1\Accounts\WhatsApp\TemplatesController::class,
                'index'
            ])->name('whatsapp.templates.index');
            
            Route::post('templates/{inbox_id}/sync', [
                \App\Http\Controllers\Api\V1\Accounts\WhatsApp\TemplatesController::class,
                'sync'
            ])->name('whatsapp.templates.sync');
        });
        
        // Instagram Routes
        Route::prefix('instagram')->group(function () {
            // Autorização (OAuth)
            Route::post('authorization', [
                \App\Http\Controllers\Api\V1\Accounts\Instagram\AuthorizationsController::class,
                'create'
            ])->name('instagram.authorization');
            
            // OAuth Button (página HTML no backend)
            // IMPORTANTE: Esta rota é PÚBLICA (sem autenticação) porque é carregada em iframe
            // A segurança é garantida porque o iframe é carregado pelo frontend autenticado
            // O backend valida se account existe e está ativa
            Route::get('oauth-button', [
                \App\Http\Controllers\Api\V1\Accounts\Instagram\OAuthButtonController::class,
                'show'
            ])->name('instagram.oauth.button')->withoutMiddleware(['auth:sanctum', EnsureAccountAccess::class]);
            
            // Callback OAuth
            Route::get('callback', [
                \App\Http\Controllers\Api\V1\Accounts\Instagram\AuthorizationsController::class,
                'callback'
            ])->name('instagram.callback');
            
            // Gerenciar canais
            Route::get('channels/{channel_id}', [
                \App\Http\Controllers\Api\V1\Accounts\Instagram\ChannelsController::class,
                'show'
            ])->name('instagram.channels.show');
            
            Route::put('channels/{channel_id}', [
                \App\Http\Controllers\Api\V1\Accounts\Instagram\ChannelsController::class,
                'update'
            ])->name('instagram.channels.update');
            
            // Enviar mensagens
            Route::post('inboxes/{inboxId}/messages/text', [
                \App\Http\Controllers\Api\V1\Accounts\Instagram\MessagesController::class,
                'sendText'
            ])->name('instagram.messages.text');
            
            Route::post('inboxes/{inboxId}/messages/image', [
                \App\Http\Controllers\Api\V1\Accounts\Instagram\MessagesController::class,
                'sendImage'
            ])->name('instagram.messages.image');
            
            Route::post('inboxes/{inboxId}/messages/video', [
                \App\Http\Controllers\Api\V1\Accounts\Instagram\MessagesController::class,
                'sendVideo'
            ])->name('instagram.messages.video');
        });
        
        // Facebook Routes
        Route::prefix('facebook')->group(function () {
            // Autorização (OAuth)
            Route::post('authorization', [
                \App\Http\Controllers\Api\V1\Accounts\Facebook\AuthorizationsController::class,
                'create'
            ])->name('facebook.authorization');
            
            // OAuth Button (página HTML no backend)
            // IMPORTANTE: Esta rota é PÚBLICA (sem autenticação) porque é carregada em iframe
            // A segurança é garantida porque o iframe é carregado pelo frontend autenticado
            // O backend valida se account existe e está ativa
            Route::get('oauth-button', [
                \App\Http\Controllers\Api\V1\Accounts\Facebook\OAuthButtonController::class,
                'show'
            ])->name('facebook.oauth.button')->withoutMiddleware(['auth:sanctum', EnsureAccountAccess::class]);
            
            // Lista páginas do usuário (aceita GET com query params ou POST com body)
            Route::match(['get', 'post'], 'pages', [
                \App\Http\Controllers\Api\V1\Accounts\Facebook\AuthorizationsController::class,
                'pages'
            ])->name('facebook.pages');
            
            // Registra página (cria canal)
            Route::post('register_page', [
                \App\Http\Controllers\Api\V1\Accounts\Facebook\AuthorizationsController::class,
                'registerPage'
            ])->name('facebook.register_page');
            
            // Reautoriza página
            Route::post('reauthorize_page', [
                \App\Http\Controllers\Api\V1\Accounts\Facebook\AuthorizationsController::class,
                'reauthorizePage'
            ])->name('facebook.reauthorize_page');
        });
        
        // WebWidget Routes
        Route::prefix('webwidget')->group(function () {
            // Cria canal
            Route::post('authorization', [
                \App\Http\Controllers\Api\V1\Accounts\WebWidget\AuthorizationsController::class,
                'create'
            ])->name('webwidget.authorization');
            
            // Retorna script
            Route::get('script/{channel_id}', [
                \App\Http\Controllers\Api\V1\Accounts\WebWidget\AuthorizationsController::class,
                'getScript'
            ])->name('webwidget.script');
        });
        
        // Conversations
        Route::apiResource('conversations', \App\Http\Controllers\Api\V1\Accounts\ConversationsController::class);
        
        // Messages (aninhadas em conversations)
        Route::apiResource('conversations.messages', \App\Http\Controllers\Api\V1\Accounts\MessagesController::class);
        
        // Contacts
        Route::apiResource('contacts', \App\Http\Controllers\Api\V1\Accounts\ContactsController::class);
        Route::get('contacts/instagram', [
            \App\Http\Controllers\Api\V1\Accounts\ContactsController::class,
            'instagram'
        ])->name('contacts.instagram');
        // Busca contatos diretamente da API do Instagram
        Route::get('contacts/instagram/search', [
            \App\Http\Controllers\Api\V1\Accounts\ContactsController::class,
            'searchInstagramContact'
        ])->name('contacts.instagram.search');
        Route::get('contacts/instagram/{instagramId}', [
            \App\Http\Controllers\Api\V1\Accounts\ContactsController::class,
            'getInstagramContact'
        ])->name('contacts.instagram.show');
        
        // Account (rotas vazias devem vir ANTES das rotas com parâmetros)
        Route::get('', [\App\Http\Controllers\Api\V1\Accounts\AccountsController::class, 'show']);
        Route::put('', [\App\Http\Controllers\Api\V1\Accounts\AccountsController::class, 'update']);
        
        // Inboxes - Rotas principais (plural para listar/criar, singular para operações individuais)
        // IMPORTANTE: Rotas sem parâmetros devem vir ANTES das rotas com parâmetros
        Route::get('inboxes', [\App\Http\Controllers\Api\V1\Accounts\InboxesController::class, 'index'])->name('inboxes.index');
        Route::post('inboxes', [\App\Http\Controllers\Api\V1\Accounts\InboxesController::class, 'store'])->name('inboxes.store');
        
        // Inbox - Mostrar, atualizar e deletar (singular para evitar conflito)
        // Adiciona constraint para garantir que inbox_id seja numérico
        Route::get('inbox/{inbox_id}', [\App\Http\Controllers\Api\V1\Accounts\InboxesController::class, 'show'])
            ->where('inbox_id', '[0-9]+')
            ->name('inboxes.show');
        Route::put('inbox/{inbox_id}', [\App\Http\Controllers\Api\V1\Accounts\InboxesController::class, 'update'])
            ->where('inbox_id', '[0-9]+')
            ->name('inboxes.update');
        Route::patch('inbox/{inbox_id}', [\App\Http\Controllers\Api\V1\Accounts\InboxesController::class, 'update'])
            ->where('inbox_id', '[0-9]+')
            ->name('inboxes.update.patch');
        Route::delete('inbox/{inbox_id}', [\App\Http\Controllers\Api\V1\Accounts\InboxesController::class, 'destroy'])
            ->where('inbox_id', '[0-9]+')
            ->name('inboxes.destroy');
        
        // Mantém 'inboxes/{inbox_id}' como alias para compatibilidade (deprecated)
        // Usa inbox_id para corresponder ao controller que espera int $inbox_id
        // IMPORTANTE: Estas rotas devem vir DEPOIS das rotas sem parâmetro para evitar conflito
        Route::get('inboxes/{inbox_id}', [\App\Http\Controllers\Api\V1\Accounts\InboxesController::class, 'show'])
            ->where('inbox_id', '[0-9]+')
            ->name('inboxes.show.alias');
        Route::put('inboxes/{inbox_id}', [\App\Http\Controllers\Api\V1\Accounts\InboxesController::class, 'update'])
            ->where('inbox_id', '[0-9]+')
            ->name('inboxes.update.alias');
        Route::patch('inboxes/{inbox_id}', [\App\Http\Controllers\Api\V1\Accounts\InboxesController::class, 'update'])
            ->where('inbox_id', '[0-9]+')
            ->name('inboxes.update.patch.alias');
        Route::delete('inboxes/{inbox_id}', [\App\Http\Controllers\Api\V1\Accounts\InboxesController::class, 'destroy'])
            ->where('inbox_id', '[0-9]+')
            ->name('inboxes.destroy.alias');

        // Logs (apenas para consulta)
        Route::get('logs', [\App\Http\Controllers\Api\V1\LogsController::class, 'index']);
        Route::get('logs/stats', [\App\Http\Controllers\Api\V1\LogsController::class, 'stats']);
        Route::get('logs/{id}', [\App\Http\Controllers\Api\V1\LogsController::class, 'show']);
    });


});

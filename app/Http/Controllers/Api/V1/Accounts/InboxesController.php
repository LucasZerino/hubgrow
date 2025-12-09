<?php

namespace App\Http\Controllers\Api\V1\Accounts;

use App\Models\Inbox;
use App\Support\Current;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Controller InboxesController
 * 
 * Gerencia inboxes da account.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Http\Controllers\Api\V1\Accounts
 */
class InboxesController extends BaseController
{
    /**
     * Lista todos os inboxes da account
     * 
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        try {
            Log::info('[INBOXES] index start', [
                'account_id' => Current::account()?->id,
                'user_id' => Current::user()?->id,
            ]);

            $account = Current::account();
            
            if (!$account) {
                Log::error('[INBOXES] Account não definida no contexto', [
                    'user_id' => Current::user()?->id,
                ]);
                return response()->json([
                    'error' => 'Account não definida no contexto',
                    'message' => 'Não foi possível identificar a conta atual.',
                ], 500);
            }
            
            $inboxes = $account->inboxes()
                ->with(['channel'])
                ->orderBy('name')
                ->get();

            Log::info('[INBOXES] index success', [
                'count' => $inboxes->count(),
            ]);

            return response()->json($inboxes);
        } catch (\Exception $e) {
            Log::error('[INBOXES] index error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Erro ao buscar inboxes',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mostra um inbox específico
     * 
     * @param int $inbox_id
     * @return JsonResponse
     */
    public function show(Request $request, int $inbox_id): JsonResponse
    {
        try {
            // ========== ETAPA 0: Extrai inbox_id da URL (mais confiável) ==========
            $pathSegments = explode('/', trim($request->path(), '/'));
            $inboxIdFromUrl = null;
            for ($i = count($pathSegments) - 1; $i >= 0; $i--) {
                if (is_numeric($pathSegments[$i]) && isset($pathSegments[$i-1]) && 
                    ($pathSegments[$i-1] === 'inboxes' || $pathSegments[$i-1] === 'inbox')) {
                    $inboxIdFromUrl = (int) $pathSegments[$i];
                    break;
                }
            }
            
            $route = $request->route();
            $inboxIdFromRoute = $route ? $route->parameter('inbox_id') : null;
            if (is_object($inboxIdFromRoute)) {
                $inboxIdFromRoute = $inboxIdFromRoute->id;
            }
            
            $finalInboxId = $inboxIdFromUrl ?? (int) $inboxIdFromRoute ?? $inbox_id;
            $inbox_id = $finalInboxId;
            
            try {
                Log::info('[INBOXES] ========== SHOW REQUEST START ==========', [
                    'inbox_id_from_param' => $request->route()?->parameter('inbox_id'),
                    'inbox_id_from_route' => $inboxIdFromRoute,
                    'inbox_id_from_url' => $inboxIdFromUrl,
                    'inbox_id_final' => $finalInboxId,
                    'request_path' => $request->path(),
                    'request_url' => $request->fullUrl(),
                ]);
            } catch (\Exception $e) {
                // Ignora erro de log
            }

            $account = Current::account();
            
            if (!$account) {
                Log::error('[INBOXES] show - Account não definida no contexto', [
                    'user_id' => Current::user()?->id,
                ]);
                return response()->json([
                    'error' => 'Account não definida no contexto',
                    'message' => 'Não foi possível identificar a conta atual.',
                ], 500);
            }

            // IMPORTANTE: Busca diretamente usando o modelo Inbox
            // O global scope HasAccountScope já aplica o filtro por account_id automaticamente
            $inboxModel = \App\Models\Inbox::with(['channel', 'account'])
                ->where('id', $inbox_id)
                ->first();

            if (!$inboxModel) {
                // Debug detalhado
                $inboxWithoutScope = \App\Models\Inbox::withoutGlobalScopes()
                    ->where('id', $inbox_id)
                    ->first();
                
                $inboxInOtherAccount = $inboxWithoutScope && $inboxWithoutScope->account_id !== $account->id;
                $inboxInSameAccount = $inboxWithoutScope && $inboxWithoutScope->account_id === $account->id;
                
                Log::warning('[INBOXES] show - inbox não encontrado', [
                    'inbox_id' => $inbox_id,
                    'account_id' => $account->id,
                    'inbox_exists_globally' => $inboxWithoutScope !== null,
                    'inbox_account_id' => $inboxWithoutScope?->account_id,
                    'current_account_id' => Current::account()?->id,
                    'inbox_in_other_account' => $inboxInOtherAccount,
                    'inbox_in_same_account' => $inboxInSameAccount,
                ]);
                
                // Mensagem de erro melhorada
                $errorMessage = sprintf(
                    'Inbox %d não encontrado na account %d',
                    $inbox_id,
                    $account->id
                );
                
                if ($inboxWithoutScope) {
                    if ($inboxInOtherAccount) {
                        $errorMessage .= sprintf(
                            '. O inbox existe mas pertence à account %d',
                            $inboxWithoutScope->account_id
                        );
                    } elseif ($inboxInSameAccount) {
                        $errorMessage .= '. O inbox existe na account mas não foi encontrado (possível problema de scope)';
                    }
                }
                
                return response()->json([
                    'error' => 'Inbox não encontrado',
                    'message' => $errorMessage,
                    'inbox_id' => $inbox_id,
                    'account_id' => $account->id,
                    'inbox_exists' => $inboxWithoutScope !== null,
                    'inbox_account_id' => $inboxWithoutScope?->account_id,
                ], 404);
            }
            
            // Verifica se o inbox encontrado pertence à account correta (segurança extra)
            if ($inboxModel->account_id !== $account->id) {
                Log::error('[INBOXES] show - SECURITY: inbox de outra account', [
                    'inbox_id' => $inbox_id,
                    'inbox_account_id' => $inboxModel->account_id,
                    'requested_account_id' => $account->id,
                ]);
                return response()->json([
                    'error' => 'Inbox não encontrado',
                    'message' => sprintf('Inbox %d não encontrado na account %d. O inbox pertence à account %d.', 
                        $inbox_id, 
                        $account->id, 
                        $inboxModel->account_id
                    ),
                    'inbox_id' => $inbox_id,
                    'account_id' => $account->id,
                    'inbox_account_id' => $inboxModel->account_id,
                ], 404);
            }

        return response()->json($inboxModel);
        } catch (\Exception $e) {
            Log::error('[INBOXES] show error', [
                'inbox_id' => $inbox_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Erro ao buscar inbox',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cria um novo inbox
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        // Verifica limite de inboxes
        $account = Current::account();
        if (!$account->canCreateResource('inboxes')) {
            $usage = $account->getResourceUsage('inboxes');
            return response()->json([
                'error' => 'Limite de inboxes excedido',
                'message' => 'Você atingiu o limite de inboxes para esta conta.',
                'usage' => $usage,
            ], 402);
        }

        // Verifica o tipo de canal primeiro para aplicar validações específicas
        $channelType = $request->input('channel_type');
        $isInstagram = $channelType === 'App\\Models\\Channel\\InstagramChannel';
        $isWebWidget = $channelType === 'App\\Models\\Channel\\WebWidgetChannel';
        
        // Todos os channels são criados automaticamente (seguindo padrão Chatwoot)
        // channel_id não deve ser enviado - será criado automaticamente
        $validationRules = [
            'name' => 'required|string|max:255',
            'channel_type' => 'required|string',
            'email_address' => 'nullable|email',
            'business_name' => 'nullable|string|max:255',
            'timezone' => 'nullable|string|max:50',
            'greeting_enabled' => 'nullable|boolean',
            'greeting_message' => 'nullable|string',
            'out_of_office_message' => 'nullable|string',
            'working_hours_enabled' => 'nullable|boolean',
            'enable_auto_assignment' => 'nullable|boolean',
            'auto_assignment_config' => 'nullable|array',
            'allow_messages_after_resolved' => 'nullable|boolean',
            'lock_to_single_conversation' => 'nullable|boolean',
            'csat_survey_enabled' => 'nullable|boolean',
            'csat_config' => 'nullable|array',
            'enable_email_collect' => 'nullable|boolean',
            'sender_name_type' => 'nullable|integer',
        ];
        
        // Campos específicos por tipo de canal
        if ($isInstagram) {
            // Instagram: aceita webhook_url opcional
            $validationRules['webhook_url'] = 'nullable|url|max:500';
        } elseif ($isWebWidget) {
            // WebWidget: validação de campos adicionais
            $validationRules['website_url'] = 'nullable|url|max:500';
            $validationRules['widget_logo'] = 'nullable|file|mimes:svg|max:2048'; // Apenas SVG, max 2MB
            $validationRules['webhook_url'] = 'nullable|url|max:500';
            $validationRules['welcome_title'] = 'nullable|string|max:255';
            $validationRules['welcome_tagline'] = 'nullable|string|max:255';
            $validationRules['greeting_message'] = 'nullable|string|max:1000';
            $validationRules['widget_color'] = 'nullable|string|max:7';
        }
        
        $validated = $request->validate($validationRules);

        // Verifica se a app está configurada para canais que requerem credenciais
        $appName = $this->getAppNameFromChannelType($channelType);
        
        if ($appName && !\App\Support\AppConfigHelper::isConfigured($appName)) {
            return response()->json([
                'error' => ucfirst($appName) . ' não configurado',
                'message' => 'O SuperAdmin precisa configurar as credenciais do ' . ucfirst($appName) . ' antes de criar inboxes.',
            ], 503);
        }

        $validated['account_id'] = Current::account()->id;

        // Upload do logo se presente (apenas para WebWidget)
        if ($isWebWidget && $request->hasFile('widget_logo')) {
            $path = $request->file('widget_logo')->store('widget_logos', 'public');
            $validated['widget_logo'] = Storage::url($path);
        }
        
        // Sempre cria channel automaticamente (seguindo padrão Chatwoot)
        $channel = $this->createChannelForInbox($channelType, $validated);
        $validated['channel_id'] = $channel->id;
        
        // Remove campos que não são do inbox (são específicos do channel)
        $channelFields = [
            'webhook_url', 'website_url', 'widget_logo', 'widget_color',
            'welcome_title', 'welcome_tagline', 'greeting_message'
        ];
        
        foreach ($channelFields as $field) {
            if (isset($validated[$field])) {
                unset($validated[$field]);
            }
        }
        
        // Define is_active baseado no tipo de canal
        // Canais que precisam de OAuth/credenciais começam inativos
        $requiresAuth = in_array($channelType, [
            'App\\Models\\Channel\\InstagramChannel',
            'App\\Models\\Channel\\WhatsAppChannel',
            'App\\Models\\Channel\\FacebookChannel',
        ]);
        
        $validated['is_active'] = $requiresAuth 
            ? false 
            : ($appName ? \App\Support\AppConfigHelper::isConfigured($appName) : true);

        $inbox = Inbox::create($validated);
        $inbox->load(['channel', 'account']);

        return response()->json($inbox, 201);
    }

    /**
     * Retorna o nome da app baseado no tipo de canal
     * 
     * @param string $channelType
     * @return string|null
     */
    protected function getAppNameFromChannelType(string $channelType): ?string
    {
        return match ($channelType) {
            'App\\Models\\Channel\\InstagramChannel' => 'instagram',
            'App\\Models\\Channel\\WhatsAppChannel' => 'whatsapp',
            'App\\Models\\Channel\\FacebookChannel' => 'facebook',
            default => null,
        };
    }

    /**
     * Retorna o nome da tabela do banco de dados baseado no tipo de canal
     * 
     * @param string $channelType
     * @return string|null
     */
    protected function getChannelTableName(string $channelType): ?string
    {
        return match ($channelType) {
            'App\\Models\\Channel\\InstagramChannel' => 'instagram_channels',
            'App\\Models\\Channel\\WhatsAppChannel' => 'whatsapp_channels',
            'App\\Models\\Channel\\FacebookChannel' => 'facebook_channels',
            'App\\Models\\Channel\\WebWidgetChannel' => 'web_widget_channels',
            default => null,
        };
    }

    /**
     * Cria channel automaticamente baseado no tipo
     * 
     * @param string $channelType
     * @param array $validated
     * @return \App\Models\Channel\InstagramChannel|\App\Models\Channel\WhatsAppChannel|\App\Models\Channel\FacebookChannel|\App\Models\Channel\WebWidgetChannel
     */
    protected function createChannelForInbox(string $channelType, array $validated)
    {
        $accountId = Current::account()->id;
        
        return match ($channelType) {
            'App\\Models\\Channel\\InstagramChannel' => $this->createInstagramChannel($accountId, $validated),
            'App\\Models\\Channel\\WebWidgetChannel' => $this->createWebWidgetChannel($accountId, $validated),
            'App\\Models\\Channel\\WhatsAppChannel' => $this->createWhatsAppChannel($accountId, $validated),
            'App\\Models\\Channel\\FacebookChannel' => $this->createFacebookChannel($accountId, $validated),
            default => throw new \InvalidArgumentException("Tipo de canal não suportado: {$channelType}"),
        };
    }

    /**
     * Cria channel Instagram (placeholder que será atualizado no OAuth)
     */
    protected function createInstagramChannel(int $accountId, array $validated)
    {
        $temporaryInstagramId = 'temp_' . $accountId . '_' . time() . '_' . uniqid();
        
        $channelData = [
            'account_id' => $accountId,
            'instagram_id' => $temporaryInstagramId,
            'access_token' => 'temp_token',
            'expires_at' => now()->addYear(),
        ];
        
        if (!empty($validated['webhook_url'])) {
            $channelData['webhook_url'] = $validated['webhook_url'];
        }
        
        $channel = \App\Models\Channel\InstagramChannel::create($channelData);
        
        Log::info('[INBOXES] Channel Instagram criado automaticamente', [
            'channel_id' => $channel->id,
            'temporary_instagram_id' => $temporaryInstagramId,
        ]);
        
        return $channel;
    }

    /**
     * Cria channel WebWidget
     */
    protected function createWebWidgetChannel(int $accountId, array $validated)
    {
        $channelData = [
            'account_id' => $accountId,
            'website_url' => $validated['website_url'] ?? 'https://placeholder.com',
            'widget_color' => $validated['widget_color'] ?? '#1f93ff',
            'reply_time' => \App\Models\Channel\WebWidgetChannel::REPLY_TIME_FEW_MINUTES,
            'pre_chat_form_enabled' => false,
            'pre_chat_form_options' => $this->getDefaultPreChatFormOptions(),
            'continuity_via_email' => true,
            'hmac_mandatory' => false,
            'feature_flags' => 7,
        ];
        
        // Campos opcionais adicionais
        if (!empty($validated['widget_logo'])) {
            $channelData['widget_logo'] = $validated['widget_logo'];
        }
        
        if (!empty($validated['webhook_url'])) {
            $channelData['webhook_url'] = $validated['webhook_url'];
        }
        
        if (!empty($validated['welcome_title'])) {
            $channelData['welcome_title'] = $validated['welcome_title'];
        }
        
        if (!empty($validated['welcome_tagline'])) {
            $channelData['welcome_tagline'] = $validated['welcome_tagline'];
        }
        
        if (!empty($validated['greeting_message'])) {
            $channelData['greeting_message'] = $validated['greeting_message'];
        }
        
        $channel = \App\Models\Channel\WebWidgetChannel::create($channelData);
        
        Log::info('[INBOXES] Channel WebWidget criado automaticamente', [
            'channel_id' => $channel->id,
            'website_url' => $channelData['website_url'],
            'has_logo' => !empty($channelData['widget_logo']),
            'has_webhook' => !empty($channelData['webhook_url']),
        ]);
        
        return $channel;
    }

    /**
     * Retorna opções padrão do formulário pré-chat
     * 
     * @return array
     */
    protected function getDefaultPreChatFormOptions(): array
    {
        return [
            'pre_chat_message' => 'Share your queries or comments here.',
            'pre_chat_fields' => [
                [
                    'field_type' => 'standard',
                    'label' => 'Email Id',
                    'name' => 'emailAddress',
                    'type' => 'email',
                    'required' => true,
                    'enabled' => false,
                ],
                [
                    'field_type' => 'standard',
                    'label' => 'Full name',
                    'name' => 'fullName',
                    'type' => 'text',
                    'required' => false,
                    'enabled' => false,
                ],
                [
                    'field_type' => 'standard',
                    'label' => 'Phone number',
                    'name' => 'phoneNumber',
                    'type' => 'text',
                    'required' => false,
                    'enabled' => false,
                ],
            ],
        ];
    }

    /**
     * Cria channel WhatsApp (placeholder que será atualizado na autorização)
     */
    protected function createWhatsAppChannel(int $accountId, array $validated)
    {
        $temporaryPhoneNumber = 'temp_' . $accountId . '_' . time() . '_' . uniqid();
        
        $channelData = [
            'account_id' => $accountId,
            'phone_number' => $temporaryPhoneNumber,
            'provider' => \App\Models\Channel\WhatsAppChannel::PROVIDER_WHATSAPP_CLOUD,
            'provider_config' => [
                'webhook_verify_token' => \Illuminate\Support\Str::random(32),
            ],
        ];
        
        $channel = \App\Models\Channel\WhatsAppChannel::create($channelData);
        
        Log::info('[INBOXES] Channel WhatsApp criado automaticamente (placeholder)', [
            'channel_id' => $channel->id,
            'temporary_phone_number' => $temporaryPhoneNumber,
        ]);
        
        return $channel;
    }

    /**
     * Cria channel Facebook (placeholder que será atualizado na autorização)
     */
    protected function createFacebookChannel(int $accountId, array $validated)
    {
        $temporaryPageId = 'temp_' . $accountId . '_' . time() . '_' . uniqid();
        
        $channelData = [
            'account_id' => $accountId,
            'page_id' => $temporaryPageId,
            'page_access_token' => 'temp_token',
            'user_access_token' => 'temp_token',
        ];
        
        $channel = \App\Models\Channel\FacebookChannel::create($channelData);
        
        Log::info('[INBOXES] Channel Facebook criado automaticamente (placeholder)', [
            'channel_id' => $channel->id,
            'temporary_page_id' => $temporaryPageId,
        ]);
        
        return $channel;
    }

    /**
     * Atualiza um inbox
     * 
     * @param Request $request
     * @param int $inbox_id
     * @return JsonResponse
     */
    public function update(Request $request, int $inbox_id): JsonResponse
    {
        try {
            // ========== ETAPA 0: Extrai inbox_id da URL (mais confiável) ==========
            // IMPORTANTE: Extrai diretamente da URL para evitar problemas com route model binding
            $pathSegments = explode('/', trim($request->path(), '/'));
            // A URL pode ser: api/v1/accounts/1/inboxes/3 ou api/v1/accounts/1/inbox/3
            // Então o inbox_id está no último segmento numérico após 'inboxes' ou 'inbox'
            $inboxIdFromUrl = null;
            for ($i = count($pathSegments) - 1; $i >= 0; $i--) {
                if (is_numeric($pathSegments[$i]) && isset($pathSegments[$i-1]) && 
                    ($pathSegments[$i-1] === 'inboxes' || $pathSegments[$i-1] === 'inbox')) {
                    $inboxIdFromUrl = (int) $pathSegments[$i];
                    break;
                }
            }
            
            // Prioriza o ID da URL, depois da rota, depois do parâmetro
            $route = $request->route();
            $inboxIdFromRoute = $route ? $route->parameter('inbox_id') : null;
            if (is_object($inboxIdFromRoute)) {
                $inboxIdFromRoute = $inboxIdFromRoute->id;
            }
            
            // Usa o ID mais confiável
            $finalInboxId = $inboxIdFromUrl ?? (int) $inboxIdFromRoute ?? $inbox_id;
            
            // ========== ETAPA 1: Log inicial da requisição ==========
            try {
                Log::info('[INBOXES] ========== UPDATE REQUEST START ==========', [
                    'inbox_id_from_param' => $inbox_id,
                    'inbox_id_from_route' => $inboxIdFromRoute,
                    'inbox_id_from_url' => $inboxIdFromUrl,
                    'inbox_id_final' => $finalInboxId,
                    'request_path' => $request->path(),
                    'request_url' => $request->fullUrl(),
                    'request_method' => $request->method(),
                    'route_name' => $route?->getName(),
                    'route_params' => $route?->parameters(),
                    'path_segments' => $pathSegments,
                ]);
            } catch (\Exception $e) {
                Log::warning('[INBOXES] Erro ao fazer log inicial', [
                    'error' => $e->getMessage(),
                    'inbox_id' => $finalInboxId,
                ]);
            }
            
            // Usa o ID final extraído
            $inbox_id = $finalInboxId;
            
            // Log de confirmação do ID usado
            Log::info('[INBOXES] update - ID FINAL A SER USADO', [
                'inbox_id_final' => $inbox_id,
                'inbox_id_from_url' => $inboxIdFromUrl,
                'inbox_id_from_route' => $inboxIdFromRoute,
                'inbox_id_from_param_original' => $request->route()?->parameter('inbox_id'),
            ]);

            // ========== ETAPA 2: Verifica Current::account() ==========
            $account = Current::account();
            
            Log::info('[INBOXES] update - Current::account() check', [
                'account_found' => $account !== null,
                'account_id' => $account?->id,
                'account_name' => $account?->name,
                'user_id' => Current::user()?->id,
                'inbox_id_being_used' => $inbox_id, // Confirma que estamos usando o ID correto
            ]);
            
            if (!$account) {
                Log::error('[INBOXES] update - Account não definida no contexto', [
                    'user_id' => Current::user()?->id,
                    'current_account_set' => Current::account() !== null,
                ]);
                return response()->json([
                    'error' => 'Account não definida no contexto',
                    'message' => 'Não foi possível identificar a conta atual.',
                ], 500);
            }

            // ========== ETAPA 3: Busca o inbox COM scope (filtro automático por account_id) ==========
            try {
                Log::info('[INBOXES] update - Buscando inbox COM global scope', [
                    'inbox_id' => $inbox_id,
                    'account_id' => $account->id,
                    'current_account_id' => Current::account()?->id,
                    'note' => 'O global scope HasAccountScope deve aplicar where(inboxes.account_id, ' . $account->id . ')',
                ]);
            } catch (\Exception $e) {
                // Ignora erro de log
            }
            
            try {
                $inboxModel = \App\Models\Inbox::where('id', $inbox_id)->first();
            } catch (\Exception $e) {
                Log::error('[INBOXES] update - Erro ao buscar inbox', [
                    'inbox_id' => $inbox_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return response()->json([
                    'error' => 'Erro ao buscar inbox',
                    'message' => $e->getMessage(),
                ], 500);
            }
            
            try {
                Log::info('[INBOXES] update - Resultado da busca COM scope', [
                    'inbox_found' => $inboxModel !== null,
                    'inbox_id' => $inboxModel?->id,
                    'inbox_account_id' => $inboxModel?->account_id,
                    'requested_account_id' => $account->id,
                ]);
            } catch (\Exception $e) {
                // Ignora erro de log
            }
            
            // ========== ETAPA 4: Se não encontrou, faz debug detalhado ==========
            if (!$inboxModel) {
                try {
                    Log::warning('[INBOXES] update - Inbox NÃO encontrado COM scope, iniciando debug...');
                } catch (\Exception $e) {
                    // Ignora erro de log
                }
                
                // Busca sem scope para verificar se existe em qualquer account
                $inboxWithoutScope = null;
                $allAccountInboxIds = [];
                $inboxInOtherAccount = false;
                $inboxInSameAccount = false;
                
                try {
                    $inboxWithoutScope = \App\Models\Inbox::withoutGlobalScopes()
                        ->where('id', $inbox_id)
                        ->first();
                    
                    try {
                        Log::info('[INBOXES] update - Busca SEM scope (debug)', [
                            'inbox_exists_globally' => $inboxWithoutScope !== null,
                            'inbox_id' => $inboxWithoutScope?->id,
                            'inbox_account_id' => $inboxWithoutScope?->account_id,
                            'inbox_name' => $inboxWithoutScope?->name,
                        ]);
                    } catch (\Exception $e) {
                        // Ignora erro de log
                    }
                    
                    // Lista todos os inboxes da account usando o relacionamento
                    $allAccountInboxIds = $account->inboxes()->pluck('id')->toArray();
                    
                    try {
                        Log::info('[INBOXES] update - Todos os inboxes da account', [
                            'account_id' => $account->id,
                            'all_inbox_ids' => $allAccountInboxIds,
                            'inbox_id_in_list' => in_array($inbox_id, $allAccountInboxIds),
                        ]);
                    } catch (\Exception $e) {
                        // Ignora erro de log
                    }
                    
                    // Verifica se o inbox existe mas em outra account
                    $inboxInOtherAccount = $inboxWithoutScope && $inboxWithoutScope->account_id !== $account->id;
                    
                    // Verifica se o inbox existe na mesma account (sem scope)
                    $inboxInSameAccount = $inboxWithoutScope && $inboxWithoutScope->account_id === $account->id;
                } catch (\Exception $e) {
                    Log::error('[INBOXES] update - Erro ao fazer debug', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
                
                // Verifica se Current::account() está definido corretamente
                $currentAccountId = Current::account()?->id;
                
                // ========== ETAPA 5: Log completo do problema ==========
                try {
                    $diagnosis = $inboxInSameAccount 
                        ? 'PROBLEMA DE SCOPE: Inbox existe na account mas não foi encontrado com scope'
                        : ($inboxInOtherAccount 
                            ? 'Inbox existe mas em outra account'
                            : 'Inbox não existe no banco de dados');
                    
                    Log::error('[INBOXES] update - ========== INBOX NÃO ENCONTRADO ==========', [
                        'inbox_id' => $inbox_id,
                        'account_id_from_param' => $account->id,
                        'current_account_id' => $currentAccountId,
                        'accounts_match' => $account->id === $currentAccountId,
                        'inbox_exists_globally' => $inboxWithoutScope !== null,
                        'inbox_account_id' => $inboxWithoutScope?->account_id,
                        'inbox_in_other_account' => $inboxInOtherAccount,
                        'inbox_in_same_account' => $inboxInSameAccount,
                        'all_account_inbox_ids' => $allAccountInboxIds,
                        'inbox_id_in_list' => in_array($inbox_id, $allAccountInboxIds ?? []),
                        'global_scope_account_id' => Current::account()?->id,
                        'diagnosis' => $diagnosis,
                    ]);
                    
                    // Se o inbox existe na mesma account mas não foi encontrado, pode ser problema de scope
                    if ($inboxInSameAccount && $inboxWithoutScope) {
                        Log::error('[INBOXES] update - PROBLEMA DE SCOPE DETECTADO!', [
                            'inbox_id' => $inbox_id,
                            'inbox_account_id' => $inboxWithoutScope->account_id,
                            'current_account_id' => $currentAccountId,
                            'accounts_match' => $inboxWithoutScope->account_id === $currentAccountId,
                            'problem' => 'O inbox existe na account correta mas o global scope não está funcionando',
                        ]);
                    }
                } catch (\Exception $e) {
                    // Ignora erro de log, mas registra
                    error_log('[INBOXES] Erro ao fazer log de debug: ' . $e->getMessage());
                }
                
                // Mensagem de erro melhorada
                $errorMessage = sprintf(
                    'Inbox %d não encontrado na account %d',
                    $inbox_id,
                    $account->id
                );
                
                if ($inboxWithoutScope) {
                    if ($inboxInOtherAccount) {
                        $errorMessage .= sprintf(
                            '. O inbox existe mas pertence à account %d',
                            $inboxWithoutScope->account_id
                        );
                    } elseif ($inboxInSameAccount) {
                        $errorMessage .= '. O inbox existe na account mas não foi encontrado (possível problema de scope)';
                    }
                }
                
                return response()->json([
                    'error' => 'Inbox não encontrado',
                    'message' => $errorMessage,
                    'inbox_id' => $inbox_id,
                    'account_id' => $account->id,
                    'inbox_exists' => $inboxWithoutScope !== null,
                    'inbox_account_id' => $inboxWithoutScope?->account_id,
                ], 404);
            }
            
            // ========== ETAPA 6: Verifica se o inbox encontrado pertence à account correta (segurança extra) ==========
            if ($inboxModel->account_id !== $account->id) {
                Log::error('[INBOXES] update - SECURITY: inbox de outra account', [
                    'inbox_id' => $inbox_id,
                    'inbox_account_id' => $inboxModel->account_id,
                    'requested_account_id' => $account->id,
                ]);
                return response()->json([
                    'error' => 'Inbox não encontrado',
                    'message' => sprintf('Inbox %d não encontrado na account %d. O inbox pertence à account %d.', 
                        $inbox_id, 
                        $account->id, 
                        $inboxModel->account_id
                    ),
                    'inbox_id' => $inbox_id,
                    'account_id' => $account->id,
                    'inbox_account_id' => $inboxModel->account_id,
                ], 404);
            }
            
            Log::info('[INBOXES] update - Inbox encontrado e validado', [
                'inbox_id' => $inboxModel->id,
                'inbox_name' => $inboxModel->name,
                'account_id' => $inboxModel->account_id,
            ]);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email_address' => 'nullable|email',
            'business_name' => 'nullable|string|max:255',
            'timezone' => 'nullable|string|max:50',
            'greeting_enabled' => 'nullable|boolean',
            'greeting_message' => 'nullable|string',
            'out_of_office_message' => 'nullable|string',
            'working_hours_enabled' => 'nullable|boolean',
            'enable_auto_assignment' => 'nullable|boolean',
            'auto_assignment_config' => 'nullable|array',
            'allow_messages_after_resolved' => 'nullable|boolean',
            'lock_to_single_conversation' => 'nullable|boolean',
            'csat_survey_enabled' => 'nullable|boolean',
            'csat_config' => 'nullable|array',
            'enable_email_collect' => 'nullable|boolean',
            'sender_name_type' => 'nullable|integer',
            'channel' => 'nullable|array',
            'channel.webhook_url' => 'nullable|url|max:500',
            'channel.website_url' => 'nullable|url|max:500',
            'channel.widget_color' => 'nullable|string|max:7',
            'channel.welcome_title' => 'nullable|string|max:255',
            'channel.welcome_tagline' => 'nullable|string|max:255',
            'channel.pre_chat_form_enabled' => 'nullable|boolean',
            'channel.pre_chat_form_options' => 'nullable|array',
        ]);

        // Atualiza campos do inbox (exceto channel)
        $inboxData = $validated;
        unset($inboxData['channel']);
        $inboxModel->update($inboxData);

        // Atualiza channel se fornecido
        if (isset($validated['channel']) && !empty($validated['channel'])) {
            $channel = $inboxModel->channel;
            
            if ($channel) {
                $channelData = [];
                $channelInput = $validated['channel'];
                
                // Atualiza webhook_url para canais Instagram
                if (isset($channelInput['webhook_url']) && 
                    $inboxModel->channel_type === 'App\\Models\\Channel\\InstagramChannel') {
                    $channelData['webhook_url'] = $channelInput['webhook_url'];
                }
                
                // Atualiza webhook_url para canais Facebook
                if (isset($channelInput['webhook_url']) && 
                    $inboxModel->channel_type === 'App\\Models\\Channel\\FacebookChannel') {
                    $channelData['webhook_url'] = $channelInput['webhook_url'];
                }
                
                // Atualiza campos para canais WebWidget
                if ($inboxModel->channel_type === 'App\\Models\\Channel\\WebWidgetChannel') {
                    if (isset($channelInput['website_url'])) {
                        $channelData['website_url'] = $channelInput['website_url'];
                    }
                    if (isset($channelInput['widget_color'])) {
                        $channelData['widget_color'] = $channelInput['widget_color'];
                    }
                    if (isset($channelInput['welcome_title'])) {
                        $channelData['welcome_title'] = $channelInput['welcome_title'];
                    }
                    if (isset($channelInput['welcome_tagline'])) {
                        $channelData['welcome_tagline'] = $channelInput['welcome_tagline'];
                    }
                    if (isset($channelInput['pre_chat_form_enabled'])) {
                        $channelData['pre_chat_form_enabled'] = $channelInput['pre_chat_form_enabled'];
                    }
                    if (isset($channelInput['pre_chat_form_options'])) {
                        $channelData['pre_chat_form_options'] = $channelInput['pre_chat_form_options'];
                    }
                }
                
                // Atualiza outros campos do channel se necessário
                if (!empty($channelData)) {
                    $channel->update($channelData);
                    Log::info('[INBOXES] Channel atualizado', [
                        'inbox_id' => $inboxModel->id,
                        'channel_id' => $channel->id,
                        'channel_type' => $inboxModel->channel_type,
                        'updated_fields' => array_keys($channelData),
                    ]);
                }
            }
        }

        $inboxModel->load(['channel', 'account']);

        return response()->json($inboxModel);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('[INBOXES] update - validação falhou', [
                'inbox_id' => $inbox_id,
                'errors' => $e->errors(),
            ]);
            return response()->json([
                'error' => 'Dados inválidos',
                'message' => 'Os dados fornecidos não são válidos.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('[INBOXES] update error', [
                'inbox_id' => $inbox_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Erro ao atualizar inbox',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Deleta um inbox
     * 
     * @param Request $request
     * @param int $inbox ID do inbox (vem da rota, mas não fazemos route model binding)
     * @return JsonResponse
     */
    public function destroy(Request $request, int $inbox_id): JsonResponse
    {
        try {
            // ========== ETAPA 0: Extrai inbox_id da URL (mais confiável) ==========
            $pathSegments = explode('/', trim($request->path(), '/'));
            $inboxIdFromUrl = null;
            for ($i = count($pathSegments) - 1; $i >= 0; $i--) {
                if (is_numeric($pathSegments[$i]) && isset($pathSegments[$i-1]) && 
                    ($pathSegments[$i-1] === 'inboxes' || $pathSegments[$i-1] === 'inbox')) {
                    $inboxIdFromUrl = (int) $pathSegments[$i];
                    break;
                }
            }
            
            $route = $request->route();
            $inboxIdFromRoute = $route ? $route->parameter('inbox_id') : null;
            if (is_object($inboxIdFromRoute)) {
                $inboxIdFromRoute = $inboxIdFromRoute->id;
            }
            
            $finalInboxId = $inboxIdFromUrl ?? (int) $inboxIdFromRoute ?? $inbox_id;
            $inboxId = $finalInboxId;
            
            try {
                Log::info('[INBOXES] ========== DESTROY REQUEST START ==========', [
                    'inbox_id_from_param' => $inbox_id,
                    'inbox_id_from_route' => $inboxIdFromRoute,
                    'inbox_id_from_url' => $inboxIdFromUrl,
                    'inbox_id_final' => $finalInboxId,
                    'request_path' => $request->path(),
                    'request_url' => $request->fullUrl(),
                    'request_method' => $request->method(),
                    'path_segments' => $pathSegments,
                ]);
            } catch (\Exception $e) {
                // Ignora erro de log
            }
            
            if (!$inboxId || $inboxId <= 0) {
                Log::error('[INBOXES] destroy - inbox ID inválido', [
                    'inbox_id_from_route' => $inboxIdFromRoute,
                    'inbox_id_final' => $inboxId,
                ]);
                return response()->json([
                    'error' => 'ID do inbox inválido',
                    'message' => sprintf('O ID do inbox fornecido (%s) é inválido.', $inboxId),
                    'inbox_id' => $inboxId,
                ], 400);
            }

            $account = Current::account();
            if (!$account) {
                Log::error('[INBOXES] destroy - account não definida');
                return response()->json([
                    'error' => 'Account não definida',
                    'message' => 'Não foi possível identificar a conta atual.',
                ], 500);
            }

            try {
                Log::info('[INBOXES] destroy - buscando inbox', [
                    'inbox_id_to_search' => $inboxId,
                    'account_id' => $account->id,
                ]);
            } catch (\Exception $e) {
                // Ignora erro de log
            }

            // IMPORTANTE: Busca diretamente usando o modelo Inbox
            // O global scope HasAccountScope já aplica o filtro por account_id automaticamente
            $inboxModel = \App\Models\Inbox::where('id', $inboxId)->first();
            
            if (!$inboxModel) {
                // Tenta buscar sem o scope para verificar se o inbox existe em outra account
                $inboxWithoutScope = \App\Models\Inbox::withoutGlobalScopes()
                    ->where('id', $inboxId)
                    ->first();
                
                $inboxInOtherAccount = $inboxWithoutScope && $inboxWithoutScope->account_id !== $account->id;
                $inboxInSameAccount = $inboxWithoutScope && $inboxWithoutScope->account_id === $account->id;
                
                try {
                    Log::error('[INBOXES] destroy - inbox não encontrado', [
                        'inbox_id' => $inboxId,
                        'account_id' => $account->id,
                        'inbox_exists_globally' => $inboxWithoutScope !== null,
                        'inbox_account_id' => $inboxWithoutScope?->account_id,
                        'inbox_in_other_account' => $inboxInOtherAccount,
                        'inbox_in_same_account' => $inboxInSameAccount,
                        'current_account_id' => Current::account()?->id,
                    ]);
                } catch (\Exception $e) {
                    // Ignora erro de log
                }

                // Mensagem de erro melhorada
                $errorMessage = sprintf(
                    'Inbox %d não encontrado na account %d',
                    $inboxId,
                    $account->id
                );
                
                if ($inboxWithoutScope) {
                    if ($inboxInOtherAccount) {
                        $errorMessage .= sprintf(
                            '. O inbox existe mas pertence à account %d',
                            $inboxWithoutScope->account_id
                        );
                    } elseif ($inboxInSameAccount) {
                        $errorMessage .= '. O inbox existe na account mas não foi encontrado (possível problema de scope)';
                    }
                }

                return response()->json([
                    'error' => 'Inbox não encontrado',
                    'message' => $errorMessage,
                    'inbox_id' => $inboxId,
                    'account_id' => $account->id,
                    'inbox_exists' => $inboxWithoutScope !== null,
                    'inbox_account_id' => $inboxWithoutScope?->account_id,
                ], 404);
            }
            
            // Verifica se o inbox encontrado pertence à account correta (segurança extra)
            if ($inboxModel->account_id !== $account->id) {
                Log::error('[INBOXES] destroy - SECURITY: inbox de outra account', [
                    'inbox_id' => $inboxId,
                    'inbox_account_id' => $inboxModel->account_id,
                    'requested_account_id' => $account->id,
                ]);
                return response()->json([
                    'error' => 'Inbox não encontrado',
                    'message' => sprintf('Inbox %d não encontrado na account %d. O inbox pertence à account %d.', 
                        $inboxId, 
                        $account->id, 
                        $inboxModel->account_id
                    ),
                    'inbox_id' => $inboxId,
                    'account_id' => $account->id,
                    'inbox_account_id' => $inboxModel->account_id,
                ], 404);
            }
            
            Log::info('[INBOXES] destroy - inbox encontrado', [
                'inbox_id' => $inboxModel->id,
                'inbox_name' => $inboxModel->name,
                'inbox_account_id' => $inboxModel->account_id,
                'channel_id' => $inboxModel->channel_id,
                'channel_type' => $inboxModel->channel_type,
            ]);
            
            // Carrega o channel antes de deletar
            $inboxModel->load('channel');
            $channel = $inboxModel->channel;
            
            // Deleta o channel associado primeiro (se existir)
            if ($channel) {
                Log::info('[INBOXES] destroy - deletando channel associado', [
                    'channel_id' => $channel->id,
                    'channel_type' => $inboxModel->channel_type,
                ]);
                
                // Remove webhooks do channel antes de deletar (se o método existir)
                if (method_exists($channel, 'teardownWebhooks')) {
                    try {
                        $channel->teardownWebhooks();
                    } catch (\Exception $e) {
                        Log::warning('[INBOXES] destroy - erro ao remover webhooks do channel', [
                            'channel_id' => $channel->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                
                $channel->delete();
                Log::info('[INBOXES] destroy - channel deletado', [
                    'channel_id' => $channel->id,
                ]);
            } else {
                Log::warning('[INBOXES] destroy - inbox sem channel associado', [
                    'inbox_id' => $inboxModel->id,
                    'channel_id' => $inboxModel->channel_id,
                ]);
            }
            
            // Deleta o inbox
            $inboxModel->delete();

            Log::info('[INBOXES] destroy success', [
                'inbox_id' => $inboxId,
            ]);

            return response()->json(['message' => 'Inbox deletado com sucesso']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('[INBOXES] destroy - ModelNotFoundException', [
                'inbox_id_from_param' => $inbox_id ?? null,
                'inbox_id_from_route' => $request->route('inbox'),
                'account_id' => Current::account()?->id,
                'error' => $e->getMessage(),
                'model' => $e->getModel(),
                'ids' => $e->getIds(),
            ]);

            return response()->json([
                'error' => 'Inbox não encontrado',
                'message' => 'O inbox especificado não foi encontrado.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('[INBOXES] destroy error', [
                'inbox_id_from_param' => $inbox_id ?? null,
                'inbox_id_from_route' => $request->route('inbox'),
                'account_id' => Current::account()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Erro ao deletar inbox',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}

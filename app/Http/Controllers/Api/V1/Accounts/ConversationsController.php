<?php

namespace App\Http\Controllers\Api\V1\Accounts;

use App\Models\Conversation;
use App\Models\Inbox;
use App\Support\Current;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller ConversationsController
 * 
 * Gerencia conversas da account.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Http\Controllers\Api\V1\Accounts
 */
class ConversationsController extends BaseController
{
    /**
     * Lista todas as conversas da account
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        // FORÇA escrita imediata do log
        \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] ========== CONTROLLER INDEX START ==========');
        
        \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Request details', [
            'path' => $request->path(),
            'full_url' => $request->fullUrl(),
            'method' => $request->method(),
            'account_id' => \App\Support\Current::account()?->id,
            'user_id' => \App\Support\Current::user()?->id,
            'params' => $request->all(),
            'query_string' => $request->getQueryString(),
            'has_limit' => $request->has('limit'),
            'limit_value' => $request->get('limit'),
            'all_value' => $request->get('all'),
        ]);
        
        try {
            $startTime = microtime(true);

            $account = Current::account();
            if (!$account) {
                \Illuminate\Support\Facades\Log::error('[CONVERSATIONS] Account não encontrada');
                return response()->json(['error' => 'Account não encontrada'], 404);
            }

            \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Building query');
            
            try {
                // Query simples sem eager loading para testar se é isso que está causando o problema
                $query = Conversation::withoutGlobalScopes()
                    ->where('conversations.account_id', $account->id)
                    ->select([
                        'conversations.id',
                        'conversations.account_id',
                        'conversations.inbox_id',
                        'conversations.contact_id',
                        'conversations.display_id',
                        'conversations.status',
                        'conversations.priority',
                        'conversations.assignee_id',
                        'conversations.last_activity_at',
                        'conversations.created_at',
                    ])
                    ->orderBy('conversations.id', 'desc');
                    
                \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Query object created successfully');
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('[CONVERSATIONS] Error creating query', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }

            // Filtros opcionais
            if ($request->has('status')) {
                $query->where('conversations.status', $request->status);
            }

            if ($request->has('inbox_id')) {
                $query->where('conversations.inbox_id', $request->inbox_id);
            }

            if ($request->has('assignee_id')) {
                $query->where('conversations.assignee_id', $request->assignee_id);
            }

            \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Query built, executing');

            // Se solicitado, retorna todos sem paginação
            // Usa boolean() que aceita: 'true', '1', 1, true, 'on', 'yes'
            $shouldReturnAll = $request->boolean('all');
            
            \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Checking all parameter', [
                'all_param_raw' => $request->get('all'),
                'all_bool' => $shouldReturnAll,
                'all_params' => $request->all(),
            ]);
            
            if ($shouldReturnAll) {
                \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] ====== ENTERING all=true BRANCH ======');
                
                // Aplica limit se fornecido, senão usa padrão de 50 para evitar timeout
                $limitParam = $request->get('limit');
                if ($limitParam !== null && $limitParam !== '') {
                    $limitValue = (int) $limitParam;
                    if ($limitValue > 0) {
                        $query->limit($limitValue);
                        \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Limit applied', [
                            'limit' => $limitValue,
                        ]);
                    }
                } else {
                    // Sem limit fornecido, aplica padrão de 100 para evitar timeout
                    $query->limit(100);
                    \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Default limit applied (100)');
                }
                
                // Carrega relacionamentos necessários
                $query->with(['contact:id,account_id,name,email,phone_number,avatar_url']);
                
                // Executa query
                \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Executing query with all=true');
                
                try {
                    $items = $query->get();
                    \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Query executed successfully', [
                        'count' => $items->count(),
                    ]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('[CONVERSATIONS] Query failed', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    throw $e;
                }
                
                // PASSO 2: Serialização dos dados incluindo relacionamentos
                \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] STEP 2: Serializing data with relationships');
                
                try {
                    $responseData = [];
                    foreach ($items as $item) {
                        $conversationData = [
                            'id' => $item->id,
                            'account_id' => $item->account_id,
                            'inbox_id' => $item->inbox_id,
                            'contact_id' => $item->contact_id,
                            'display_id' => $item->display_id,
                            'status' => $item->status,
                            'priority' => $item->priority,
                            'assignee_id' => $item->assignee_id,
                            'last_activity_at' => $item->last_activity_at?->toISOString(),
                            'created_at' => $item->created_at?->toISOString(),
                        ];
                        
                        // Adiciona informações do contato
                        if ($item->relationLoaded('contact') && $item->contact) {
                            $conversationData['contact'] = [
                                'id' => $item->contact->id,
                                'name' => $item->contact->name,
                                'email' => $item->contact->email,
                                'phone_number' => $item->contact->phone_number,
                                'avatar_url' => $item->contact->avatar_url,
                            ];
                        }
                        
                        $responseData[] = $conversationData;
                    }
                    
                    \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] STEP 2 SUCCESS: Data serialized', [
                        'count' => count($responseData),
                    ]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('[CONVERSATIONS] STEP 2 ERROR: Serialization failed', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    throw $e;
                }
                
                // PASSO 3: JSON encoding e resposta
                \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] STEP 3: Encoding JSON and returning');
                
                try {
                    $json = response()->json($responseData);
                    \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] STEP 3 SUCCESS: Response created', [
                        'status' => $json->getStatusCode(),
                        'content_length' => strlen($json->getContent()),
                    ]);
                    return $json;
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('[CONVERSATIONS] STEP 3 ERROR: Response creation failed', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    throw $e;
                }
            }

            // Paginação padrão (relacionamentos já carregados via with() na query)
            \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Paginating');
            
            $conversations = $query->paginate($request->get('per_page', 20));
            
            // Previne lazy loading do channel para evitar problemas
            // Os relacionamentos já foram carregados via with() na query inicial
            if ($conversations->isNotEmpty()) {
                $conversations->each(function ($item) {
                    if ($item->inbox) {
                        $item->inbox->setRelation('channel', null);
                    }
                });
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] index end', [
                'count' => $conversations->count(),
                'page' => $conversations->currentPage(),
                'duration_ms' => $duration,
            ]);

            \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Preparing JSON response');

            try {
                // Testa serialização antes de criar resposta (igual ao fluxo de all=true)
                $testArray = $conversations->toArray();
                $testJson = json_encode($testArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('JSON encoding error: ' . json_last_error_msg());
                }
                
                $json = response()->json($conversations);
                
                \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] JSON response prepared', [
                    'content_length' => strlen($json->getContent()),
                    'json_valid' => json_last_error() === JSON_ERROR_NONE,
                    'total_items' => $conversations->total(),
                ]);
                
                return $json;
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('[CONVERSATIONS] Error preparing JSON', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('[CONVERSATIONS] index error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'error' => 'Erro ao buscar conversas',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mostra uma conversa específica
     * 
     * @param Request $request
     * @param int|Conversation $conversation
     * @return JsonResponse
     */
    public function show(Request $request, $conversation): JsonResponse
    {
        $account = Current::account();
        if (!$account) {
            return response()->json(['error' => 'Account não encontrada'], 404);
        }

        // Extrai o conversation_id dos parâmetros da rota
        $routeParams = $request->route()->parameters();
        $conversationIdFromRoute = $routeParams['conversation'] ?? null;
        
        // Se o parâmetro da rota é um objeto, pega o ID dele
        // Se é um número, usa diretamente
        if ($conversationIdFromRoute instanceof Conversation) {
            $conversationId = $conversationIdFromRoute->id;
        } elseif (is_numeric($conversationIdFromRoute)) {
            $conversationId = (int) $conversationIdFromRoute;
        } else {
            // Fallback: tenta extrair do objeto $conversation
            $conversationId = $conversation instanceof Conversation ? $conversation->id : (int) $conversation;
        }

        // Busca a conversa sem global scopes e valida se pertence à account
        $conversation = Conversation::withoutGlobalScopes()
            ->where('id', $conversationId)
            ->where('account_id', $account->id)
            ->with(['inbox', 'contact', 'assignee', 'messages'])
            ->firstOrFail();

        return response()->json($conversation);
    }

    /**
     * Cria uma nova conversa
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] store method called', [
            'path' => $request->path(),
            'method' => $request->method(),
            'has_message' => $request->has('message'),
            'message_content' => $request->input('message.content'),
            'inbox_id' => $request->input('inbox_id'),
            'contact_id' => $request->input('contact_id'),
        ]);
        
        try {
            $account = Current::account();
            
            \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Account retrieved', [
                'account_id' => $account->id,
            ]);
            
            // Validação básica primeiro (apenas tipos, sem exists para evitar problemas com global scopes)
            // IMPORTANTE: Não usamos exists aqui porque o global scope do Inbox filtra por account_id
            // Fazemos validação customizada depois usando $account->inboxes()->find()
            $validationRules = [
                'inbox_id' => [
                    'required',
                    'integer',
                    'min:1',
                ],
                'contact_id' => [
                    'required',
                    'integer',
                    'min:1',
                ],
                'status' => 'nullable|integer',
                'priority' => 'nullable|integer',
                'assignee_id' => 'nullable|integer|exists:users,id',
                'custom_attributes' => 'nullable|array',
                'message' => 'nullable|array',
                'message.content' => 'required_with:message|string',
                'message.message_type' => 'nullable|integer|in:' . \App\Models\Message::TYPE_INCOMING . ',' . \App\Models\Message::TYPE_OUTGOING,
                'message.content_type' => 'nullable|string',
            ];
            
            \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Validation rules', [
                'rules' => $validationRules,
                'request_data' => $request->all(),
            ]);
            
            try {
                $validated = $request->validate($validationRules);
            } catch (\Illuminate\Validation\ValidationException $e) {
                \Illuminate\Support\Facades\Log::error('[CONVERSATIONS] Validation failed in basic rules', [
                    'errors' => $e->errors(),
                    'request_data' => $request->all(),
                ]);
                throw $e;
            }

            \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Basic validation passed', [
                'validated_inbox_id' => $validated['inbox_id'],
                'validated_contact_id' => $validated['contact_id'],
                'account_id' => $account->id,
            ]);

            // Validação customizada: verifica se o inbox existe e pertence à account
            // Usa withoutGlobalScopes() para evitar problemas com o global scope
            // e filtra explicitamente por account_id
            $inbox = Inbox::withoutGlobalScopes()
                ->where('id', $validated['inbox_id'])
                ->where('account_id', $account->id)
                ->first();
            
            // Lista todos os inboxes da account para debug
            $allAccountInboxIds = Inbox::withoutGlobalScopes()
                ->where('account_id', $account->id)
                ->pluck('id')
                ->toArray();
            
            \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Checking inbox availability', [
                'requested_inbox_id' => $validated['inbox_id'],
                'account_id' => $account->id,
                'all_account_inbox_ids' => $allAccountInboxIds,
                'inbox_id_in_list' => in_array($validated['inbox_id'], $allAccountInboxIds),
                'inbox_found' => $inbox !== null,
            ]);
                
            \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Inbox lookup via account relationship', [
                'inbox_id' => $validated['inbox_id'],
                'account_id' => $account->id,
                'inbox_found' => $inbox !== null,
                'inbox_account_id' => $inbox?->account_id,
                'inbox_name' => $inbox?->name,
            ]);
            
            if (!$inbox) {
                // Verifica se o inbox existe mas pertence a outra account (para debug)
                $inboxExistsGlobally = Inbox::withoutGlobalScopes()
                    ->where('id', $validated['inbox_id'])
                    ->exists();
                    
                $inboxInOtherAccount = Inbox::withoutGlobalScopes()
                    ->where('id', $validated['inbox_id'])
                    ->where('account_id', '!=', $account->id)
                    ->exists();
                    
                $inboxInSameAccount = Inbox::withoutGlobalScopes()
                    ->where('id', $validated['inbox_id'])
                    ->where('account_id', $account->id)
                    ->exists();
                    
                \Illuminate\Support\Facades\Log::warning('[CONVERSATIONS] Inbox not found in account scope', [
                    'inbox_id' => $validated['inbox_id'],
                    'account_id' => $account->id,
                    'inbox_exists_globally' => $inboxExistsGlobally,
                    'inbox_in_other_account' => $inboxInOtherAccount,
                    'inbox_in_same_account' => $inboxInSameAccount,
                    'all_account_inboxes' => $allAccountInboxIds,
                ]);
                
                return response()->json([
                    'error' => 'Erro de validação',
                    'message' => 'Os dados fornecidos são inválidos.',
                    'errors' => [
                        'inbox_id' => ['O inbox especificado não existe ou não pertence a esta conta.'],
                    ],
                ], 422);
            }

            // Validação customizada: verifica se o contact existe e pertence à account
            // Usa withoutGlobalScopes() para evitar problemas com o global scope
            // e filtra explicitamente por account_id
            $contact = \App\Models\Contact::withoutGlobalScopes()
                ->where('id', $validated['contact_id'])
                ->where('account_id', $account->id)
                ->first();
                
            \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Contact lookup via account relationship', [
                'contact_id' => $validated['contact_id'],
                'account_id' => $account->id,
                'contact_found' => $contact !== null,
                'contact_account_id' => $contact?->account_id,
                'contact_name' => $contact?->name,
            ]);
            
            if (!$contact) {
                // Verifica se o contato existe mas pertence a outra account (para debug)
                $contactExistsGlobally = \App\Models\Contact::withoutGlobalScopes()
                    ->where('id', $validated['contact_id'])
                    ->exists();
                    
                $contactInOtherAccount = \App\Models\Contact::withoutGlobalScopes()
                    ->where('id', $validated['contact_id'])
                    ->where('account_id', '!=', $account->id)
                    ->exists();
                    
                \Illuminate\Support\Facades\Log::warning('[CONVERSATIONS] Contact not found in account scope', [
                    'contact_id' => $validated['contact_id'],
                    'account_id' => $account->id,
                    'contact_exists_globally' => $contactExistsGlobally,
                    'contact_in_other_account' => $contactInOtherAccount,
                    'all_account_contacts' => $account->contacts()->pluck('id')->toArray(),
                ]);
                
                return response()->json([
                    'error' => 'Erro de validação',
                    'message' => 'Os dados fornecidos são inválidos.',
                    'errors' => [
                        'contact_id' => ['O contato especificado não existe ou não pertence a esta conta.'],
                    ],
                ], 422);
            }

        // Já validamos que inbox e contact pertencem à account atual na validação acima

        // Usa transação para garantir atomicidade
        $conversation = null;
        $message = null;
        
        \Illuminate\Support\Facades\DB::transaction(function () use ($account, $inbox, $contact, $validated, &$conversation, &$message): void {
            // Busca ou cria ContactInbox
            $contactInbox = \App\Models\ContactInbox::firstOrCreate([
                'contact_id' => $contact->id,
                'inbox_id' => $inbox->id,
            ], [
                'source_id' => $contact->id . '_' . $inbox->id,
            ]);

            \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] ContactInbox ready', [
                'contact_inbox_id' => $contactInbox->id,
            ]);

            // Calcula display_id
            $maxDisplayId = Conversation::where('account_id', $account->id)
                ->max('display_id') ?? 0;

            $conversationData = [
                'account_id' => $account->id,
                'inbox_id' => $inbox->id, // Usa o objeto $inbox, não o validated['inbox_id']
                'contact_id' => $contact->id, // Usa o objeto $contact, não o validated['contact_id']
                'contact_inbox_id' => $contactInbox->id,
                'display_id' => $maxDisplayId + 1,
                'status' => $validated['status'] ?? Conversation::STATUS_OPEN,
                'priority' => $validated['priority'] ?? Conversation::PRIORITY_MEDIUM,
                'assignee_id' => $validated['assignee_id'] ?? null,
                'last_activity_at' => now(),
                'custom_attributes' => $validated['custom_attributes'] ?? [],
            ];

            \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Creating conversation', [
                'conversation_data' => $conversationData,
            ]);

            $conversation = Conversation::create($conversationData);

            \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Conversation created', [
                'conversation_id' => $conversation->id,
                'conversation_inbox_id' => $conversation->inbox_id,
                'conversation_contact_id' => $conversation->contact_id,
            ]);

            // Se uma mensagem foi fornecida, cria ela junto com a conversa
            // IMPORTANTE: A conversa já foi criada, então podemos usar $conversation->id
            if (isset($validated['message']) && !empty($validated['message']['content'])) {
                $messageData = $validated['message'];
                
                \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Creating message', [
                    'conversation_id' => $conversation->id,
                    'inbox_id' => $inbox->id,
                    'contact_id' => $contact->id,
                    'message_content' => $messageData['content'],
                ]);
                
                $message = \App\Models\Message::create([
                    'account_id' => $account->id,
                    'inbox_id' => $inbox->id,
                    'conversation_id' => $conversation->id, // Usa o ID da conversa recém-criada
                    'sender_id' => $contact->id,
                    'content' => $messageData['content'],
                    'message_type' => $messageData['message_type'] ?? \App\Models\Message::TYPE_INCOMING,
                    'content_type' => $messageData['content_type'] ?? \App\Models\Message::CONTENT_TYPE_TEXT,
                    'status' => \App\Models\Message::STATUS_DELIVERED,
                ]);

                \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] Message created', [
                    'message_id' => $message->id,
                    'message_conversation_id' => $message->conversation_id,
                    'message_inbox_id' => $message->inbox_id,
                ]);

                // Atualiza last_activity_at da conversa com o timestamp da mensagem
                $conversation->update(['last_activity_at' => $message->created_at]);
            }
        });

        // Garante que a conversa foi criada
        if (!$conversation) {
            \Illuminate\Support\Facades\Log::error('[CONVERSATIONS] Conversation was not created in transaction');
            return response()->json([
                'error' => 'Erro ao criar conversa',
                'message' => 'Não foi possível criar a conversa.',
            ], 500);
        }

        // Carrega relacionamentos após a transação
        $conversation->load(['inbox', 'contact', 'assignee', 'messages']);

        $response = ['conversation' => $conversation];
        if ($message) {
            $response['message'] = $message;
        }

        \Illuminate\Support\Facades\Log::info('[CONVERSATIONS] store success', [
            'conversation_id' => $conversation->id,
            'message_id' => $message?->id,
        ]);

        return response()->json($response, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Illuminate\Support\Facades\Log::error('[CONVERSATIONS] store validation error', [
                'errors' => $e->errors(),
            ]);
            throw $e;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('[CONVERSATIONS] store error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Atualiza uma conversa
     * 
     * @param Request $request
     * @param int|Conversation $conversation
     * @return JsonResponse
     */
    public function update(Request $request, $conversation): JsonResponse
    {
        $account = Current::account();
        if (!$account) {
            return response()->json(['error' => 'Account não encontrada'], 404);
        }

        // Extrai o conversation_id dos parâmetros da rota
        $routeParams = $request->route()->parameters();
        $conversationIdFromRoute = $routeParams['conversation'] ?? null;
        
        if ($conversationIdFromRoute instanceof Conversation) {
            $conversationId = $conversationIdFromRoute->id;
        } elseif (is_numeric($conversationIdFromRoute)) {
            $conversationId = (int) $conversationIdFromRoute;
        } else {
            $conversationId = $conversation instanceof Conversation ? $conversation->id : (int) $conversation;
        }

        // Busca a conversa sem global scopes e valida se pertence à account
        $conversation = Conversation::withoutGlobalScopes()
            ->where('id', $conversationId)
            ->where('account_id', $account->id)
            ->firstOrFail();

        $validated = $request->validate([
            'status' => 'sometimes|integer|in:' . implode(',', [
                Conversation::STATUS_OPEN,
                Conversation::STATUS_RESOLVED,
                Conversation::STATUS_PENDING,
            ]),
            'priority' => 'sometimes|integer|in:' . implode(',', [
                Conversation::PRIORITY_LOW,
                Conversation::PRIORITY_MEDIUM,
                Conversation::PRIORITY_HIGH,
                Conversation::PRIORITY_URGENT,
            ]),
            'assignee_id' => 'nullable|integer|exists:users,id',
            'custom_attributes' => 'nullable|array',
            'snoozed_until' => 'nullable|date',
        ]);

        $conversation->update($validated);
        $conversation->load(['inbox', 'contact', 'assignee']);

        return response()->json($conversation);
    }


    /**
     * Deleta uma conversa
     * 
     * @param Request $request
     * @param int|Conversation $conversation
     * @return JsonResponse
     */
    public function destroy(Request $request, $conversation): JsonResponse
    {
        $account = Current::account();
        if (!$account) {
            return response()->json(['error' => 'Account não encontrada'], 404);
        }

        // Extrai o conversation_id dos parâmetros da rota
        $routeParams = $request->route()->parameters();
        $conversationIdFromRoute = $routeParams['conversation'] ?? null;
        
        if ($conversationIdFromRoute instanceof Conversation) {
            $conversationId = $conversationIdFromRoute->id;
        } elseif (is_numeric($conversationIdFromRoute)) {
            $conversationId = (int) $conversationIdFromRoute;
        } else {
            $conversationId = $conversation instanceof Conversation ? $conversation->id : (int) $conversation;
        }

        // Busca a conversa sem global scopes e valida se pertence à account
        $conversation = Conversation::withoutGlobalScopes()
            ->where('id', $conversationId)
            ->where('account_id', $account->id)
            ->firstOrFail();
            
        $conversation->delete();

        return response()->json(['message' => 'Conversa deletada com sucesso']);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Accounts;

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\Current;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Controller MessagesController
 * 
 * Gerencia mensagens dentro de conversas.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Http\Controllers\Api\V1\Accounts
 */
class MessagesController extends BaseController
{
    /**
     * Lista mensagens de uma conversa
     * 
     * @param Request $request
     * @param int $conversation_id
     * @return JsonResponse
     */
    public function index(Request $request, $conversation): JsonResponse
    {
        // IMPORTANTE: Extrai o conversation_id diretamente dos parâmetros da rota
        // O Laravel pode fazer route model binding e retornar a conversa errada devido ao escopo global
        $routeParams = $request->route()->parameters();
        $conversationIdFromRoute = $routeParams['conversation'] ?? null;
        
        // Se o parâmetro da rota é um objeto, pega o ID dele
        // Se é um número, usa diretamente
        if ($conversationIdFromRoute instanceof Conversation) {
            $conversation_id = $conversationIdFromRoute->id;
        } elseif (is_numeric($conversationIdFromRoute)) {
            $conversation_id = (int) $conversationIdFromRoute;
        } else {
            // Fallback: tenta extrair do objeto $conversation
            $conversation_id = $conversation instanceof Conversation ? $conversation->id : (int) $conversation;
        }
        
        // Log para debug ANTES de carregar a conversa
        error_log('[MESSAGES CONTROLLER] ========== INDEX CHAMADO ==========');
        error_log('[MESSAGES CONTROLLER] Request path: ' . $request->path());
        error_log('[MESSAGES CONTROLLER] Request route: ' . ($request->route() ? $request->route()->getName() : 'null'));
        error_log('[MESSAGES CONTROLLER] Route parameters (raw): ' . json_encode($routeParams));
        error_log('[MESSAGES CONTROLLER] Conversation parameter type: ' . gettype($conversationIdFromRoute));
        error_log('[MESSAGES CONTROLLER] Conversation ID extraído da rota: ' . $conversation_id);
        
        // Carrega a conversa SEM escopos globais para garantir que encontra a conversa correta
        $conversation = Conversation::withoutGlobalScopes()->findOrFail($conversation_id);

        // Garante que a account está definida no contexto para o escopo global funcionar
        if ($conversation->account) {
            \App\Support\Current::setAccount($conversation->account);
        }
        
        // Log para debug
        error_log('[MESSAGES CONTROLLER] Conversation encontrada: ID=' . $conversation->id . ', Account ID=' . $conversation->account_id);
        error_log('[MESSAGES CONTROLLER] Inbox ID: ' . $conversation->inbox_id);
        error_log('[MESSAGES CONTROLLER] Contact ID: ' . $conversation->contact_id);
        
        // Validação crítica: verifica se a conversa solicitada corresponde à encontrada
        if ($conversation_id != $conversation->id) {
            error_log('[MESSAGES CONTROLLER] ❌ ERRO CRÍTICO: Conversation ID solicitado (' . $conversation_id . ') diferente do encontrado (' . $conversation->id . ')');
            return response()->json(['error' => 'Conversation not found'], 404);
        }
        
        // IMPORTANTE: Usa o conversation_id da URL diretamente, não do objeto conversation
        // Isso garante que estamos filtrando pela conversa correta
        $conversationIdToFilter = $conversation_id; // Usa o ID da URL, não do objeto
        $accountIdToFilter = $conversation->account_id;
        
        // Log claro dos filtros que serão aplicados
        error_log('[MESSAGES CONTROLLER] ========================================');
        error_log('[MESSAGES CONTROLLER] FILTROS QUE SERÃO APLICADOS:');
        error_log('[MESSAGES CONTROLLER]   - account_id = ' . $accountIdToFilter);
        error_log('[MESSAGES CONTROLLER]   - conversation_id = ' . $conversationIdToFilter);
        error_log('[MESSAGES CONTROLLER] ========================================');
        error_log('[MESSAGES CONTROLLER] Conversation object ID: ' . $conversation->id);
        error_log('[MESSAGES CONTROLLER] Conversation ID da URL: ' . $conversation_id);
        
        // IMPORTANTE: Remove escopos globais temporariamente e aplica filtros explícitos
        // O escopo global pode estar interferindo com o filtro por conversation_id
        $query = Message::withoutGlobalScopes()
            ->where('conversation_id', '=', $conversationIdToFilter) // Usa o ID da URL
            ->where('account_id', '=', $accountIdToFilter) // Usa o account_id da conversa
            ->with(['sender', 'attachments'])
            ->orderBy('created_at', 'desc');

        // Log da query SQL
        error_log('[MESSAGES CONTROLLER] Query SQL: ' . $query->toSql());
        error_log('[MESSAGES CONTROLLER] Bindings: ' . json_encode($query->getBindings()));
        
        // Verifica quantas mensagens existem para esta conversa antes de aplicar paginação
        $totalMessages = (clone $query)->count();
        error_log('[MESSAGES CONTROLLER] Total de mensagens na conversa ' . $conversation->id . ': ' . $totalMessages);
        
        // Validação adicional: verifica se as mensagens retornadas realmente pertencem à conversa correta
        if ($totalMessages > 0) {
            $sampleMessage = (clone $query)->first();
            if ($sampleMessage && $sampleMessage->conversation_id != $conversation->id) {
                error_log('[MESSAGES CONTROLLER] ⚠️ AVISO: Mensagem retornada pertence à conversa ' . $sampleMessage->conversation_id . ', mas esperado ' . $conversation->id);
            }
        }

        // Suporte a paginação cursor-based (before/after) para scroll infinito
        $perPage = $request->get('per_page', 20); // Padrão: 20 mensagens
        $before = $request->get('before'); // ID da mensagem mais antiga para carregar anteriores
        $after = $request->get('after'); // ID da mensagem mais recente para carregar posteriores

        if ($before) {
            // Carrega mensagens anteriores à mensagem especificada
            $query->where('id', '<', $before);
        } elseif ($after) {
            // Carrega mensagens posteriores à mensagem especificada
            $query->where('id', '>', $after);
        }

        // Executa a query e converte para modelos Message
        $messageIds = $query->limit($perPage)->pluck('id');
        error_log('[MESSAGES CONTROLLER] IDs de mensagens encontradas: ' . $messageIds->implode(', '));
        
        // Carrega os modelos Message com relacionamentos
        // IMPORTANTE: Usa o conversation_id da URL, não do objeto
        error_log('[MESSAGES CONTROLLER] Carregando mensagens com filtros:');
        error_log('[MESSAGES CONTROLLER]   - account_id = ' . $accountIdToFilter);
        error_log('[MESSAGES CONTROLLER]   - conversation_id = ' . $conversationIdToFilter);
        error_log('[MESSAGES CONTROLLER]   - message_ids: ' . $messageIds->implode(', '));
        
        $messages = Message::withoutGlobalScopes()
            ->whereIn('id', $messageIds)
            ->where('conversation_id', '=', $conversationIdToFilter) // Filtro adicional de segurança usando ID da URL
            ->where('account_id', '=', $accountIdToFilter) // Filtro adicional por account
            ->with(['sender', 'attachments'])
            ->orderBy('created_at', 'desc')
            ->get();
        
        // Log dos resultados ANTES da validação
        error_log('[MESSAGES CONTROLLER] Total de mensagens retornadas pela query: ' . $messages->count());
        if ($messages->count() > 0) {
            $firstMsg = $messages->first();
            error_log('[MESSAGES CONTROLLER] Primeira mensagem ANTES filtro: ID=' . $firstMsg->id . ', Conversation ID=' . $firstMsg->conversation_id . ', Esperado: ' . $conversationIdToFilter);
        }
        
        // Validação crítica: filtra mensagens que não pertencem à conversa correta
        // Isso é uma camada extra de segurança caso o banco de dados tenha dados inconsistentes
        // IMPORTANTE: Usa o conversation_id da URL, não do objeto
        $originalCount = $messages->count();
        $messages = $messages->filter(function ($message) use ($conversationIdToFilter) {
            $belongs = $message->conversation_id == $conversationIdToFilter;
            if (!$belongs) {
                error_log('[MESSAGES CONTROLLER] ⚠️ Mensagem ID=' . $message->id . ' filtrada: conversation_id=' . $message->conversation_id . ' (esperado: ' . $conversationIdToFilter . ')');
            }
            return $belongs;
        })->values();
        
        // Log dos resultados DEPOIS da validação
        error_log('[MESSAGES CONTROLLER] Total de mensagens após filtro: ' . $messages->count() . ' (removidas: ' . ($originalCount - $messages->count()) . ')');
        if ($messages->count() > 0) {
            error_log('[MESSAGES CONTROLLER] Primeira mensagem: ID=' . $messages->first()->id . ', Conversation ID=' . $messages->first()->conversation_id);
            error_log('[MESSAGES CONTROLLER] Última mensagem: ID=' . $messages->last()->id . ', Conversation ID=' . $messages->last()->conversation_id);
        } else {
            error_log('[MESSAGES CONTROLLER] ⚠️ Nenhuma mensagem encontrada para a conversa ' . $conversationIdToFilter . ' após filtro');
        }

        // Retorna em ordem crescente (mais antigas primeiro) para facilitar no frontend
        $messages = $messages->reverse()->values();

        return response()->json([
            'data' => $messages,
            'meta' => [
                'per_page' => $perPage,
                'count' => $messages->count(),
                'has_more' => $messages->count() === $perPage,
                'oldest_id' => $messages->first()?->id,
                'newest_id' => $messages->last()?->id,
            ],
        ]);
    }

    /**
     * Mostra uma mensagem específica
     * 
     * @param Request $request
     * @param $conversation
     * @param int|Message $message
     * @return JsonResponse
     */
    public function show(Request $request, $conversation, $message): JsonResponse
    {
        $account = Current::account();
        if (!$account) {
            return response()->json(['error' => 'Account não encontrada'], 404);
        }

        // Extrai o conversation_id e message_id dos parâmetros da rota
        $routeParams = $request->route()->parameters();
        $conversationIdFromRoute = $routeParams['conversation'] ?? null;
        $messageIdFromRoute = $routeParams['message'] ?? null;
        
        if ($conversationIdFromRoute instanceof Conversation) {
            $conversationId = $conversationIdFromRoute->id;
        } elseif (is_numeric($conversationIdFromRoute)) {
            $conversationId = (int) $conversationIdFromRoute;
        } else {
            $conversationId = $conversation instanceof Conversation ? $conversation->id : (int) $conversation;
        }
        
        if ($messageIdFromRoute instanceof Message) {
            $messageId = $messageIdFromRoute->id;
        } elseif (is_numeric($messageIdFromRoute)) {
            $messageId = (int) $messageIdFromRoute;
        } else {
            $messageId = $message instanceof Message ? $message->id : (int) $message;
        }
        
        // Busca a conversa sem global scopes e valida se pertence à account
        $conversation = Conversation::withoutGlobalScopes()
            ->where('id', $conversationId)
            ->where('account_id', $account->id)
            ->firstOrFail();
        
        // Busca a mensagem sem global scopes e valida se pertence à conversa e account
        $message = Message::withoutGlobalScopes()
            ->where('id', $messageId)
            ->where('conversation_id', $conversation->id)
            ->where('account_id', $account->id)
            ->with(['sender', 'attachments'])
            ->firstOrFail();

        return response()->json($message);
    }

    /**
     * Cria uma nova mensagem
     * 
     * @param Request $request
     * @param int $conversation_id
     * @return JsonResponse
     */
    public function store(Request $request, $conversation): JsonResponse
    {
        // IMPORTANTE: Extrai o conversation_id diretamente dos parâmetros da rota
        // O Laravel pode fazer route model binding e retornar a conversa errada devido ao escopo global
        $routeParams = $request->route()->parameters();
        $conversationIdFromRoute = $routeParams['conversation'] ?? null;
        
        // Se o parâmetro da rota é um objeto, pega o ID dele
        // Se é um número, usa diretamente
        if ($conversationIdFromRoute instanceof Conversation) {
            $conversation_id = $conversationIdFromRoute->id;
        } elseif (is_numeric($conversationIdFromRoute)) {
            $conversation_id = (int) $conversationIdFromRoute;
        } else {
            // Fallback: tenta extrair do objeto $conversation
            $conversation_id = $conversation instanceof Conversation ? $conversation->id : (int) $conversation;
        }
        
        // Log para debug
        error_log('[MESSAGES CONTROLLER] ========== STORE CHAMADO ==========');
        error_log('[MESSAGES CONTROLLER] Request path: ' . $request->path());
        error_log('[MESSAGES CONTROLLER] Route parameters (raw): ' . json_encode($routeParams));
        error_log('[MESSAGES CONTROLLER] Conversation ID extraído da rota: ' . $conversation_id);
        
        // Carrega a conversa se ainda não foi carregada pelo route model binding
        if (!$conversation instanceof Conversation) {
            $conversation = Conversation::withoutGlobalScopes()->findOrFail($conversation_id);
        } else {
            // Se já é um objeto, garante que está sem escopos e é a conversa correta
            $conversation = Conversation::withoutGlobalScopes()->findOrFail($conversation_id);
        }
        
        // Validação crítica: verifica se a conversa solicitada corresponde à encontrada
        if ($conversation_id != $conversation->id) {
            error_log('[MESSAGES CONTROLLER] ❌ ERRO CRÍTICO: Conversation ID solicitado (' . $conversation_id . ') diferente do encontrado (' . $conversation->id . ')');
            return response()->json(['error' => 'Conversation not found'], 404);
        }
        
        error_log('[MESSAGES CONTROLLER] Criando mensagem para:');
        error_log('[MESSAGES CONTROLLER]   - account_id = ' . $conversation->account_id);
        error_log('[MESSAGES CONTROLLER]   - conversation_id = ' . $conversation_id);

        // Validação diferente para FormData (com anexos) vs JSON
        $hasAttachments = $request->hasFile('attachments');
        
        error_log('[MESSAGES CONTROLLER] Verificando attachments:');
        error_log('[MESSAGES CONTROLLER]   - hasFile("attachments"): ' . ($hasAttachments ? 'SIM' : 'NÃO'));
        error_log('[MESSAGES CONTROLLER]   - has("attachments"): ' . ($request->has('attachments') ? 'SIM' : 'NÃO'));
        error_log('[MESSAGES CONTROLLER]   - all(): ' . json_encode($request->all()));
        error_log('[MESSAGES CONTROLLER]   - files(): ' . json_encode(array_keys($request->allFiles())));
        
        if ($hasAttachments) {
            $validated = $request->validate([
                'content' => 'nullable|string',
                'attachments' => 'required|array',
                'attachments.*' => 'file|max:10240', // 10MB max por arquivo
                'content_type' => 'nullable|string|in:' . implode(',', [
                    Message::CONTENT_TYPE_TEXT,
                    Message::CONTENT_TYPE_IMAGE,
                    Message::CONTENT_TYPE_VIDEO,
                    Message::CONTENT_TYPE_AUDIO,
                    Message::CONTENT_TYPE_FILE,
                    Message::CONTENT_TYPE_LOCATION,
                    Message::CONTENT_TYPE_CONTACT,
                ]),
                'message_type' => 'nullable|integer|in:' . implode(',', [
                    Message::TYPE_INCOMING,
                    Message::TYPE_OUTGOING,
                    Message::TYPE_ACTIVITY,
                ]),
                'private' => 'nullable|string|in:true,false,1,0', // FormData envia como string "true"/"false"
                'content_attributes' => 'nullable|string', // JSON string quando vem via FormData
            ]);
        } else {
            $validated = $request->validate([
                'content' => 'required|string',
                'content_type' => 'nullable|string|in:' . implode(',', [
                    Message::CONTENT_TYPE_TEXT,
                    Message::CONTENT_TYPE_IMAGE,
                    Message::CONTENT_TYPE_VIDEO,
                    Message::CONTENT_TYPE_AUDIO,
                    Message::CONTENT_TYPE_FILE,
                    Message::CONTENT_TYPE_LOCATION,
                    Message::CONTENT_TYPE_CONTACT,
                ]),
                'message_type' => 'nullable|integer|in:' . implode(',', [
                    Message::TYPE_INCOMING,
                    Message::TYPE_OUTGOING,
                    Message::TYPE_ACTIVITY,
                ]),
                'private' => 'nullable|boolean',
                'content_attributes' => 'nullable|array',
            ]);
        }

        // Processa content_attributes se vier como string JSON
        if (isset($validated['content_attributes']) && is_string($validated['content_attributes'])) {
            $validated['content_attributes'] = json_decode($validated['content_attributes'], true) ?? [];
        }

        // Processa private se vier como string (FormData)
        // O campo private no model é um array JSON (cast), então sempre deve ser array ou null
        $isPrivate = false;
        if (isset($validated['private'])) {
            if (is_string($validated['private'])) {
                // FormData envia como string "true" ou "false"
                $isPrivate = filter_var($validated['private'], FILTER_VALIDATE_BOOLEAN);
            } elseif (is_bool($validated['private'])) {
                // JSON pode vir como boolean
                $isPrivate = $validated['private'];
            }
        }

        $validated['account_id'] = Current::account()->id;
        $validated['inbox_id'] = $conversation->inbox_id;
        $validated['conversation_id'] = $conversation->id;
        $validated['sender_id'] = Current::user()->id;
        $validated['content'] = $validated['content'] ?? '';
        
        // Se tem attachments de áudio e content está vazio, define content_type como audio
        if ($hasAttachments && empty($validated['content'])) {
            $attachments = $request->file('attachments');
            if (!is_array($attachments)) {
                $attachments = [$attachments];
            }
            
            // Verifica se algum attachment é áudio
            // MediaRecorder pode gravar áudio como video/webm, então verificamos também pelo nome e extensão
            $hasAudio = false;
            foreach ($attachments as $file) {
                $mimeType = $file->getMimeType();
                $fileName = strtolower($file->getClientOriginalName());
                $extension = strtolower($file->getClientOriginalExtension());
                
                // Verifica por MIME type
                if (str_starts_with($mimeType, 'audio/')) {
                    $hasAudio = true;
                    error_log('[MESSAGES CONTROLLER] ✅ Áudio detectado por MIME type: ' . $mimeType);
                    break;
                }
                
                // Verifica por extensão de áudio
                $audioExtensions = ['mp3', 'wav', 'ogg', 'webm', 'm4a', 'aac', 'flac', 'opus', 'wma'];
                if (in_array($extension, $audioExtensions)) {
                    $hasAudio = true;
                    error_log('[MESSAGES CONTROLLER] ✅ Áudio detectado por extensão: ' . $extension);
                    break;
                }
                
                // Verifica pelo nome do arquivo (pode conter "audio" no nome)
                if (str_contains($fileName, 'audio') || str_contains($fileName, 'record')) {
                    $hasAudio = true;
                    error_log('[MESSAGES CONTROLLER] ✅ Áudio detectado por nome do arquivo: ' . $fileName);
                    break;
                }
                
                // Verifica se é video/webm mas tem tamanho pequeno (provavelmente é áudio)
                // Áudios geralmente são menores que vídeos
                if ($mimeType === 'video/webm' && $file->getSize() < 500000) { // Menor que 500KB
                    $hasAudio = true;
                    error_log('[MESSAGES CONTROLLER] ✅ Áudio detectado (video/webm pequeno, provavelmente áudio): ' . $fileName);
                    break;
                }
            }
            
            if ($hasAudio) {
                $validated['content_type'] = Message::CONTENT_TYPE_AUDIO;
                error_log('[MESSAGES CONTROLLER] ✅ Content type definido como AUDIO');
            } else {
                $validated['content_type'] = $validated['content_type'] ?? Message::CONTENT_TYPE_TEXT;
                error_log('[MESSAGES CONTROLLER] ⚠️ Content type mantido como TEXT (não detectado como áudio)');
            }
        } else {
        $validated['content_type'] = $validated['content_type'] ?? Message::CONTENT_TYPE_TEXT;
        }
        
        $validated['message_type'] = $validated['message_type'] ?? Message::TYPE_OUTGOING;
        
        error_log('[MESSAGES CONTROLLER] Dados da mensagem:');
        error_log('[MESSAGES CONTROLLER]   - content: "' . $validated['content'] . '"');
        error_log('[MESSAGES CONTROLLER]   - content_type: ' . $validated['content_type']);
        error_log('[MESSAGES CONTROLLER]   - message_type: ' . $validated['message_type']);
        error_log('[MESSAGES CONTROLLER]   - has_attachments: ' . ($hasAttachments ? 'SIM' : 'NÃO'));
        
        // Mensagens de saída começam com status PROGRESS (enviando)
        // O job SendReplyJob atualizará para SENT após envio bem-sucedido
        $validated['status'] = ($validated['message_type'] === Message::TYPE_OUTGOING) 
            ? Message::STATUS_PROGRESS 
            : Message::STATUS_SENT;
        
        // Converte private para array (o model faz cast para JSON)
        // O campo private no banco tem default '{}' (objeto vazio)
        // Se for privada, usa array vazio [] (que vira {} no JSON)
        // Se não for privada, usa null (Laravel usa o default do banco '{}')
        // Mas como o model faz cast para array, null pode causar problema
        // Então sempre usamos [] - array vazio é falsy, então ![] = true (não privada)
        // Para mensagens privadas, poderíamos usar ['private' => true] no futuro
        $validated['private'] = $isPrivate ? ['private' => true] : [];

        $message = Message::create($validated);
        
        // Processa anexos se houver
        if ($hasAttachments && $request->hasFile('attachments')) {
            error_log('[MESSAGES CONTROLLER] Processando attachments...');
            $attachments = $request->file('attachments');
            if (!is_array($attachments)) {
                $attachments = [$attachments];
            }
            
            error_log('[MESSAGES CONTROLLER] Total de attachments: ' . count($attachments));
            
            foreach ($attachments as $index => $file) {
                error_log('[MESSAGES CONTROLLER] Processando attachment ' . ($index + 1) . ':');
                error_log('[MESSAGES CONTROLLER]   - Nome: ' . $file->getClientOriginalName());
                error_log('[MESSAGES CONTROLLER]   - MIME: ' . $file->getMimeType());
                error_log('[MESSAGES CONTROLLER]   - Tamanho: ' . $file->getSize() . ' bytes');
                $this->processAttachment($message, $file);
            }
        } else {
            error_log('[MESSAGES CONTROLLER] ⚠️ Nenhum attachment para processar');
        }
        
        // Atualiza última atividade da conversa
        $conversation->update(['last_activity_at' => now()]);

        // Se for mensagem de saída e não for privada, envia via canal externo de forma ASSÍNCRONA
        // Usa SendReplyJob para não bloquear a requisição HTTP
        // O frontend receberá a confirmação via WebSocket quando o job processar
        $isMessagePrivate = !empty($validated['private']);
        
        if ($validated['message_type'] === Message::TYPE_OUTGOING && !$isMessagePrivate) {
            $startTime = microtime(true);
            error_log('[MESSAGES CONTROLLER] ========== ENVIANDO MENSAGEM ASSINCRONA ==========');
            error_log('[MESSAGES CONTROLLER] Message ID: ' . $message->id);
            error_log('[MESSAGES CONTROLLER] Enfileirando SendReplyJob para processamento assíncrono...');
            
            try {
                // Carrega relacionamentos necessários para o job
                $message->loadMissing(['conversation.inbox.channel']);
                
                // Enfileira job para envio assíncrono
                // O job será processado pela fila 'high' para prioridade
                \App\Jobs\SendReplyJob::dispatch($message->id)->onQueue('high');
                // \App\Jobs\SendReplyJob::dispatchSync($message->id);
                
                $elapsedTime = round((microtime(true) - $startTime) * 1000, 2);
                error_log('[MESSAGES CONTROLLER] ✅ SendReplyJob executado (ASYNC) com sucesso');
                error_log('[MESSAGES CONTROLLER] Tempo de enfileiramento: ' . $elapsedTime . 'ms');
                error_log('[MESSAGES CONTROLLER] A mensagem será enviada em background pela fila');
                
                Log::info('[MESSAGES CONTROLLER] SendReplyJob enfileirado para envio assíncrono', [
                'message_id' => $message->id,
                'queue' => 'high',
                    'elapsed_ms' => $elapsedTime,
                ]);
            } catch (\Exception $e) {
                $elapsedTime = round((microtime(true) - $startTime) * 1000, 2);
                error_log('[MESSAGES CONTROLLER] ❌ ERRO ao enfileirar SendReplyJob: ' . $e->getMessage());
                error_log('[MESSAGES CONTROLLER] Tempo até erro: ' . $elapsedTime . 'ms');
                error_log('[MESSAGES CONTROLLER] Stack trace: ' . $e->getTraceAsString());
                
                Log::error('[MESSAGES CONTROLLER] Erro ao enfileirar SendReplyJob', [
                'message_id' => $message->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
            ]);
            }
        } else {
            error_log('[MESSAGES CONTROLLER] Mensagem não será enviada (não é outgoing ou é privada)');
        }

        $message->load(['sender', 'attachments']);
        
        error_log('[MESSAGES CONTROLLER] ✅ Mensagem criada e retornada:');
        error_log('[MESSAGES CONTROLLER]   - ID: ' . $message->id);
        error_log('[MESSAGES CONTROLLER]   - content_type: ' . $message->content_type);
        error_log('[MESSAGES CONTROLLER]   - attachments count: ' . $message->attachments->count());
        if ($message->attachments->count() > 0) {
            foreach ($message->attachments as $att) {
                error_log('[MESSAGES CONTROLLER]   - Attachment ID: ' . $att->id . ', file_type: ' . $att->file_type . ', file_url: ' . ($att->file_url ?: 'VAZIO'));
            }
        }

        return response()->json($message, 201);
    }

    /**
     * Processa um anexo e cria registro no banco
     * Similar ao Chatwoot: process_attachments
     * 
     * @param Message $message
     * @param \Illuminate\Http\UploadedFile $file
     * @return Attachment
     */
    protected function processAttachment(Message $message, $file): Attachment
    {
        // Determina tipo de arquivo baseado no MIME type e nome do arquivo
        // Similar ao Chatwoot: file_type(uploaded_attachment&.content_type)
        $mimeType = $file->getMimeType();
        $fileName = $file->getClientOriginalName();
        $fileType = $this->determineFileType($mimeType, $fileName);
        
        error_log('[MESSAGES CONTROLLER] determineFileType:');
        error_log('[MESSAGES CONTROLLER]   - MIME: ' . $mimeType);
        error_log('[MESSAGES CONTROLLER]   - Nome: ' . $fileName);
        $typeNames = [
            Attachment::FILE_TYPE_IMAGE => 'IMAGE',
            Attachment::FILE_TYPE_AUDIO => 'AUDIO',
            Attachment::FILE_TYPE_VIDEO => 'VIDEO',
            Attachment::FILE_TYPE_FILE => 'FILE',
        ];
        error_log('[MESSAGES CONTROLLER]   - Tipo detectado: ' . $fileType . ' (' . ($typeNames[$fileType] ?? 'UNKNOWN') . ')');
        
        // Salva arquivo no MinIO/S3 (para gerar URLs públicas)
        // Similar ao Chatwoot: ActiveStorage com S3 (que gera URLs públicas automaticamente)
        // Estrutura: account_id/YYYY-MM-DD/arquivo.ext
        // Usa disco 's3' (MinIO) se configurado, senão usa 'public' como fallback
        $disk = env('FILESYSTEM_DISK', 'local') === 's3' ? 's3' : 'public';
        $dateFolder = now()->format('Y-m-d');
        $storagePath = "attachments/{$message->account_id}/{$dateFolder}";
        
        error_log('[MESSAGES CONTROLLER] Salvando arquivo:');
        error_log('[MESSAGES CONTROLLER]   - Disk: ' . $disk);
        error_log('[MESSAGES CONTROLLER]   - Path: ' . $storagePath);
        error_log('[MESSAGES CONTROLLER]   - File size: ' . $file->getSize() . ' bytes');
        error_log('[MESSAGES CONTROLLER]   - File valid: ' . ($file->isValid() ? 'SIM' : 'NÃO'));
        error_log('[MESSAGES CONTROLLER]   - File error: ' . ($file->getError() ?: 'NENHUM'));
        error_log('[MESSAGES CONTROLLER]   - File real path: ' . ($file->getRealPath() ?: 'NÃO DISPONÍVEL'));
        error_log('[MESSAGES CONTROLLER]   - File MIME: ' . $file->getMimeType());
        
        try {
            // Tenta salvar usando Storage diretamente se store() falhar
            $path = $file->store($storagePath, $disk);
            
            // Se store() retornar vazio, tenta usar Storage::put() diretamente
            if (!$path) {
                error_log('[MESSAGES CONTROLLER] ⚠️ store() retornou vazio, tentando Storage::put()...');
                $fileName = $file->getClientOriginalName();
                $uniqueFileName = time() . '_' . uniqid() . '_' . $fileName;
                $fullPath = $storagePath . '/' . $uniqueFileName;
                
                $content = file_get_contents($file->getRealPath());
                $saved = Storage::disk($disk)->put($fullPath, $content);
                
                if ($saved) {
                    $path = $fullPath;
                    error_log('[MESSAGES CONTROLLER] ✅ Arquivo salvo via Storage::put(): ' . $path);
                } else {
                    error_log('[MESSAGES CONTROLLER] ❌ ERRO: Storage::put() também falhou');
                    throw new \RuntimeException('Failed to store file: both store() and Storage::put() failed');
                }
            } else {
                error_log('[MESSAGES CONTROLLER] ✅ Arquivo salvo com sucesso via store(): ' . $path);
            }
        } catch (\Exception $e) {
            error_log('[MESSAGES CONTROLLER] ❌ ERRO ao salvar arquivo: ' . $e->getMessage());
            error_log('[MESSAGES CONTROLLER] Stack trace: ' . $e->getTraceAsString());
            throw $e;
        }
        
        // Obtém metadados do arquivo
        $extension = $file->getClientOriginalExtension();
        $fileName = $file->getClientOriginalName();
        $fileSize = $file->getSize();
        
        // Metadados adicionais (para imagens, pode incluir dimensões)
        $metadata = [];
        if (str_starts_with($mimeType, 'image/')) {
            try {
                $imageInfo = getimagesize($file->getRealPath());
                if ($imageInfo) {
                    $metadata['width'] = $imageInfo[0];
                    $metadata['height'] = $imageInfo[1];
                }
            } catch (\Exception $e) {
                // Ignora erros ao ler dimensões
            }
        }
        
        // Cria registro de anexo
        $attachment = Attachment::create([
            'account_id' => $message->account_id,
            'message_id' => $message->id,
            'file_type' => $fileType,
            'file_path' => $path,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'file_size' => $fileSize,
            'file_metadata' => $metadata,
        ]);
        
        error_log('[MESSAGES CONTROLLER] ✅ Attachment criado:');
        error_log('[MESSAGES CONTROLLER]   - ID: ' . $attachment->id);
        error_log('[MESSAGES CONTROLLER]   - file_type: ' . $attachment->file_type . ' (' . ($typeNames[$attachment->file_type] ?? 'UNKNOWN') . ')');
        error_log('[MESSAGES CONTROLLER]   - file_path: ' . $attachment->file_path);
        error_log('[MESSAGES CONTROLLER]   - file_url: ' . $attachment->file_url);
        
        return $attachment;
    }

    /**
     * Determina o tipo de arquivo baseado no MIME type
     * 
     * @param string $mimeType
     * @return int
     */
    protected function determineFileType(string $mimeType, ?string $fileName = null): int
    {
        if (str_starts_with($mimeType, 'image/')) {
            return Attachment::FILE_TYPE_IMAGE;
        }
        
        if (str_starts_with($mimeType, 'audio/')) {
            return Attachment::FILE_TYPE_AUDIO;
        }
        
        // MediaRecorder pode gravar áudio como video/webm
        // Verifica pelo nome do arquivo se contém "audio" ou extensões de áudio
        if ($mimeType === 'video/webm' && $fileName) {
            $fileNameLower = strtolower($fileName);
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $audioExtensions = ['mp3', 'wav', 'ogg', 'webm', 'm4a', 'aac', 'flac', 'opus', 'wma'];
            
            if (str_contains($fileNameLower, 'audio') || 
                str_contains($fileNameLower, 'record') ||
                in_array($extension, $audioExtensions)) {
                return Attachment::FILE_TYPE_AUDIO;
            }
        }
        
        if (str_starts_with($mimeType, 'video/')) {
            return Attachment::FILE_TYPE_VIDEO;
        }
        
        return Attachment::FILE_TYPE_FILE;
    }

    /**
     * Atualiza uma mensagem
     * 
     * IMPORTANTE: O Instagram não suporta edição de mensagens já enviadas.
     * Apenas status e content_attributes podem ser atualizados.
     * 
     * @param Request $request
     * @param $conversation
     * @param int|Message $message
     * @return JsonResponse
     */
    public function update(Request $request, $conversation, $message): JsonResponse
    {
        $account = Current::account();
        if (!$account) {
            return response()->json(['error' => 'Account não encontrada'], 404);
        }

        // Extrai o conversation_id dos parâmetros da rota
        $routeParams = $request->route()->parameters();
        $conversationIdFromRoute = $routeParams['conversation'] ?? null;
        $messageIdFromRoute = $routeParams['message'] ?? null;
        
        if ($conversationIdFromRoute instanceof Conversation) {
            $conversationId = $conversationIdFromRoute->id;
        } elseif (is_numeric($conversationIdFromRoute)) {
            $conversationId = (int) $conversationIdFromRoute;
        } else {
            $conversationId = $conversation instanceof Conversation ? $conversation->id : (int) $conversation;
        }
        
        if ($messageIdFromRoute instanceof Message) {
            $messageId = $messageIdFromRoute->id;
        } elseif (is_numeric($messageIdFromRoute)) {
            $messageId = (int) $messageIdFromRoute;
        } else {
            $messageId = $message instanceof Message ? $message->id : (int) $message;
        }
        
        // Busca a conversa sem global scopes e valida se pertence à account
        $conversation = Conversation::withoutGlobalScopes()
            ->where('id', $conversationId)
            ->where('account_id', $account->id)
            ->firstOrFail();
        
        // Busca a mensagem sem global scopes e valida se pertence à conversa e account
        $message = Message::withoutGlobalScopes()
            ->where('id', $messageId)
            ->where('conversation_id', $conversation->id)
            ->where('account_id', $account->id)
            ->firstOrFail();

        $validated = $request->validate([
            'content' => 'sometimes|string',
            'status' => 'sometimes|integer|in:' . implode(',', [
                Message::STATUS_SENT,
                Message::STATUS_DELIVERED,
                Message::STATUS_READ,
                Message::STATUS_FAILED,
            ]),
            'content_attributes' => 'nullable|array',
        ]);

        // IMPORTANTE: Se a mensagem já foi enviada ao Instagram (tem source_id),
        // não permite atualizar o conteúdo, apenas status e atributos
        if (isset($validated['content']) && $message->source_id) {
            Log::warning('[MESSAGES CONTROLLER] Tentativa de atualizar conteúdo de mensagem já enviada', [
                'message_id' => $message->id,
                'source_id' => $message->source_id,
            ]);
            
            // Remove content do validated se a mensagem já foi enviada
            unset($validated['content']);
            
            // Retorna aviso, mas permite atualizar outros campos
            if (count($validated) === 0) {
                return response()->json([
                    'error' => 'Não é possível atualizar o conteúdo de uma mensagem já enviada ao Instagram.',
                    'message' => 'Apenas status e content_attributes podem ser atualizados para mensagens já enviadas.',
                ], 422);
            }
        }

        $message->update($validated);
        $message->load(['sender', 'attachments']);

        return response()->json($message);
    }

    /**
     * Deleta uma mensagem
     * 
     * IMPORTANTE: O Instagram não suporta deletar mensagens já enviadas.
     * Apenas mensagens não enviadas (sem source_id) podem ser deletadas.
     * 
     * @param Request $request
     * @param $conversation
     * @param int|Message $message
     * @return JsonResponse
     */
    public function destroy(Request $request, $conversation, $message): JsonResponse
    {
        $account = Current::account();
        if (!$account) {
            return response()->json(['error' => 'Account não encontrada'], 404);
        }

        // Extrai o conversation_id e message_id dos parâmetros da rota
        $routeParams = $request->route()->parameters();
        $conversationIdFromRoute = $routeParams['conversation'] ?? null;
        $messageIdFromRoute = $routeParams['message'] ?? null;
        
        if ($conversationIdFromRoute instanceof Conversation) {
            $conversationId = $conversationIdFromRoute->id;
        } elseif (is_numeric($conversationIdFromRoute)) {
            $conversationId = (int) $conversationIdFromRoute;
        } else {
            $conversationId = $conversation instanceof Conversation ? $conversation->id : (int) $conversation;
        }
        
        if ($messageIdFromRoute instanceof Message) {
            $messageId = $messageIdFromRoute->id;
        } elseif (is_numeric($messageIdFromRoute)) {
            $messageId = (int) $messageIdFromRoute;
        } else {
            $messageId = $message instanceof Message ? $message->id : (int) $message;
        }
        
        // Busca a conversa sem global scopes e valida se pertence à account
        $conversation = Conversation::withoutGlobalScopes()
            ->where('id', $conversationId)
            ->where('account_id', $account->id)
            ->firstOrFail();
        
        // Busca a mensagem sem global scopes e valida se pertence à conversa e account
        $message = Message::withoutGlobalScopes()
            ->where('id', $messageId)
            ->where('conversation_id', $conversation->id)
            ->where('account_id', $account->id)
            ->firstOrFail();

        // IMPORTANTE: Se a mensagem já foi enviada ao Instagram (tem source_id),
        // não permite deletar (Instagram não suporta deletar mensagens)
        if ($message->source_id) {
            Log::warning('[MESSAGES CONTROLLER] Tentativa de deletar mensagem já enviada ao Instagram', [
                'message_id' => $message->id,
                'source_id' => $message->source_id,
            ]);
            
            return response()->json([
                'error' => 'Não é possível deletar uma mensagem já enviada ao Instagram.',
                'message' => 'O Instagram não suporta deletar mensagens já enviadas.',
            ], 422);
        }

        $message->delete();

        return response()->json(['message' => 'Mensagem deletada com sucesso']);
    }
}

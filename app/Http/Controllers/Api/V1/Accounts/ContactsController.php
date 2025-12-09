<?php

namespace App\Http\Controllers\Api\V1\Accounts;

use App\Models\Contact;
use App\Support\Current;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Controller ContactsController
 * 
 * Gerencia contatos da account.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Http\Controllers\Api\V1\Accounts
 */
class ContactsController extends BaseController
{
    /**
     * Lista todos os contatos da account
     * 
     * Query Parameters:
     * - search: Busca por nome, email, phone_number, identifier ou company_name
     * - email: Filtra por email exato
     * - phone_number: Filtra por telefone
     * - inbox_id: Filtra contatos de um inbox específico
     * - sort_by: Campo para ordenação (name, email, phone_number, last_activity_at, created_at)
     * - sort_order: Direção da ordenação (asc, desc) - padrão: asc
     * - per_page: Itens por página (padrão: 20, máximo: 100)
     * - page: Página atual
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        // Valida parâmetros de query
        $request->validate([
            'search' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone_number' => 'nullable|string|max:50',
            'inbox_id' => 'nullable|integer|exists:inboxes,id',
            'sort_by' => 'nullable|string|in:name,email,phone_number,last_activity_at,created_at',
            'sort_order' => 'nullable|string|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = Contact::withCount(['conversations', 'contactInboxes']);

        // Busca geral (nome, email, telefone, identifier ou company_name)
        if ($request->has('search') && !empty($request->search)) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%")
                  ->orWhere('identifier', 'like', "%{$search}%");
                
                // Busca em campos JSON (compatível com PostgreSQL e MySQL)
                $driver = DB::connection()->getDriverName();
                if ($driver === 'pgsql') {
                    $q->orWhereRaw("additional_attributes->>'company_name' ILIKE ?", ["%{$search}%"]);
                } else {
                    $q->orWhereRaw("JSON_EXTRACT(additional_attributes, '$.company_name') LIKE ?", ["%{$search}%"]);
                }
            });
        }

        // Filtro por email exato
        if ($request->has('email')) {
            $query->where('email', $request->email);
        }

        // Filtro por telefone
        if ($request->has('phone_number')) {
            $query->where('phone_number', $request->phone_number);
        }

        // Filtro por inbox (através de contactInboxes)
        if ($request->has('inbox_id')) {
            $query->whereHas('contactInboxes', function ($q) use ($request) {
                $q->where('inbox_id', $request->inbox_id);
            });
        }

        // Ordenação
        $sortBy = $request->get('sort_by', 'name');
        $sortOrder = $request->get('sort_order', 'asc');

        switch ($sortBy) {
            case 'last_activity_at':
                $query->orderBy('last_activity_at', $sortOrder === 'desc' ? 'desc' : 'asc')
                      ->orderBy('created_at', 'desc'); // Ordenação secundária
                break;
            case 'created_at':
                $query->orderBy('created_at', $sortOrder === 'desc' ? 'desc' : 'asc');
                break;
            case 'email':
            case 'phone_number':
                $query->orderBy($sortBy, $sortOrder === 'desc' ? 'desc' : 'asc')
                      ->orderBy('name', 'asc'); // Ordenação secundária
                break;
            case 'name':
            default:
                // Ordenação por nome (case-insensitive)
                $query->orderByRaw('LOWER(name) ' . ($sortOrder === 'desc' ? 'DESC' : 'ASC'))
                      ->orderBy('id', 'asc'); // Ordenação secundária para consistência
                break;
        }

        // Se solicitado, retorna todos sem paginação
        if ($request->get('all') === 'true' || $request->get('all') === true) {
            return response()->json($query->get());
        }

        // Paginação padrão
        $perPage = min($request->get('per_page', 20), 100);
        $contacts = $query->paginate($perPage);

        return response()->json($contacts);
    }

    /**
     * Mostra um contato específico
     * 
     * IMPORTANTE: Extrai o contact_id diretamente dos parâmetros da rota
     * para evitar problemas com route model binding e global scopes
     * 
     * @param Request $request
     * @param mixed $contact Parâmetro da rota (pode ser ID ou objeto Contact)
     * @return JsonResponse
     */
    public function show(Request $request, $contact): JsonResponse
    {
        $account = Current::account();
        if (!$account) {
            return response()->json(['error' => 'Account não encontrada'], 404);
        }

        // Extrai o contact_id diretamente dos parâmetros da rota
        // O Laravel pode fazer route model binding e retornar o contato errado devido ao escopo global
        $routeParams = $request->route()->parameters();
        $contactIdFromRoute = $routeParams['contact'] ?? null;
        
        // Se o parâmetro da rota é um objeto, pega o ID dele
        // Se é um número, usa diretamente
        if ($contactIdFromRoute instanceof Contact) {
            $contactId = $contactIdFromRoute->id;
        } elseif (is_numeric($contactIdFromRoute)) {
            $contactId = (int) $contactIdFromRoute;
        } else {
            // Fallback: tenta extrair do objeto $contact
            $contactId = $contact instanceof Contact ? $contact->id : (int) $contact;
        }

        // Busca o contato garantindo que pertence à account atual
        $contact = Contact::withoutGlobalScopes()
            ->where('id', $contactId)
            ->where('account_id', $account->id)
            ->with(['conversations', 'contactInboxes'])
            ->firstOrFail();

        return response()->json($contact);
    }

    /**
     * Cria um novo contato
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone_number' => 'nullable|string|max:50',
            'identifier' => 'nullable|string|max:255',
            'avatar_url' => 'nullable|url',
            'custom_attributes' => 'nullable|array',
        ]);

        $validated['account_id'] = Current::account()->id;
        $validated['last_activity_at'] = now();

        $contact = Contact::create($validated);

        return response()->json($contact, 201);
    }

    /**
     * Atualiza um contato
     * 
     * IMPORTANTE: Extrai o contact_id diretamente dos parâmetros da rota
     * para evitar problemas com route model binding e global scopes
     * 
     * @param Request $request
     * @param mixed $contact Parâmetro da rota (pode ser ID ou objeto Contact)
     * @return JsonResponse
     */
    public function update(Request $request, $contact): JsonResponse
    {
        $account = Current::account();
        if (!$account) {
            return response()->json(['error' => 'Account não encontrada'], 404);
        }

        // Extrai o contact_id diretamente dos parâmetros da rota
        $routeParams = $request->route()->parameters();
        $contactIdFromRoute = $routeParams['contact'] ?? null;
        
        // Se o parâmetro da rota é um objeto, pega o ID dele
        // Se é um número, usa diretamente
        if ($contactIdFromRoute instanceof Contact) {
            $contactId = $contactIdFromRoute->id;
        } elseif (is_numeric($contactIdFromRoute)) {
            $contactId = (int) $contactIdFromRoute;
        } else {
            // Fallback: tenta extrair do objeto $contact
            $contactId = $contact instanceof Contact ? $contact->id : (int) $contact;
        }

        // Busca o contato garantindo que pertence à account atual
        $contact = Contact::withoutGlobalScopes()
            ->where('id', $contactId)
            ->where('account_id', $account->id)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone_number' => 'nullable|string|max:50',
            'identifier' => 'nullable|string|max:255',
            'avatar_url' => 'nullable|url',
            'custom_attributes' => 'nullable|array',
        ]);

        $contact->update($validated);
        $contact->load(['conversations', 'contactInboxes']);

        return response()->json($contact);
    }

    /**
     * Lista contatos do Instagram
     * 
     * Retorna apenas contatos que têm identifier_instagram
     * 
     * Query Parameters:
     * - search: Busca por nome, email, phone_number ou identifier_instagram
     * - sort_by: Campo para ordenação (name, email, phone_number, last_activity_at, created_at)
     * - sort_order: Direção da ordenação (asc, desc) - padrão: asc
     * - per_page: Itens por página (padrão: 20, máximo: 100)
     * - page: Página atual
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function instagram(Request $request): JsonResponse
    {
        // Valida parâmetros de query
        $request->validate([
            'search' => 'nullable|string|max:255',
            'sort_by' => 'nullable|string|in:name,email,phone_number,last_activity_at,created_at',
            'sort_order' => 'nullable|string|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = Contact::withCount(['conversations', 'contactInboxes'])
            ->whereNotNull('identifier_instagram');

        // Busca geral (nome, email, telefone, identifier_instagram)
        if ($request->has('search') && !empty($request->search)) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%")
                  ->orWhere('identifier_instagram', 'like', "%{$search}%");
            });
        }

        // Ordenação
        $sortBy = $request->get('sort_by', 'name');
        $sortOrder = $request->get('sort_order', 'asc');

        switch ($sortBy) {
            case 'last_activity_at':
                $query->orderBy('last_activity_at', $sortOrder === 'desc' ? 'desc' : 'asc')
                      ->orderBy('created_at', 'desc');
                break;
            case 'created_at':
                $query->orderBy('created_at', $sortOrder === 'desc' ? 'desc' : 'asc');
                break;
            case 'email':
            case 'phone_number':
                $query->orderBy($sortBy, $sortOrder === 'desc' ? 'desc' : 'asc')
                      ->orderBy('name', 'asc');
                break;
            case 'name':
            default:
                $query->orderByRaw('LOWER(name) ' . ($sortOrder === 'desc' ? 'DESC' : 'ASC'))
                      ->orderBy('id', 'asc');
                break;
        }

        // Se solicitado, retorna todos sem paginação
        if ($request->get('all') === 'true' || $request->get('all') === true) {
            return response()->json($query->get());
        }

        // Paginação padrão
        $perPage = min($request->get('per_page', 20), 100);
        $contacts = $query->paginate($perPage);

        return response()->json($contacts);
    }

    /**
     * Busca um contato Instagram diretamente da API do Instagram por username
     * Usa Business Discovery API para buscar informações de contas Business/Creator
     * 
     * Query Parameters:
     * - username: Username do Instagram (sem @)
     * - inbox_id: ID do inbox Instagram (opcional, usa o primeiro ativo se não fornecido)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function searchInstagramContact(Request $request): JsonResponse
    {
        $account = Current::account();
        if (!$account) {
            return response()->json(['error' => 'Account não encontrada'], 404);
        }

        $request->validate([
            'username' => 'required|string|max:255',
            'inbox_id' => 'nullable|integer|exists:inboxes,id',
        ]);

        $username = ltrim($request->input('username'), '@');
        
        // Busca canal Instagram ativo
        $channel = $this->getInstagramChannel($account, $request->input('inbox_id'));
        if (!$channel) {
            return response()->json([
                'error' => 'Nenhum canal Instagram ativo encontrado',
                'message' => 'É necessário ter pelo menos um inbox Instagram ativo para buscar contatos da API',
            ], 404);
        }

        $accessToken = $channel->getAccessToken();
        if (!$accessToken) {
            return response()->json([
                'error' => 'Token de acesso não disponível',
                'message' => 'O canal Instagram precisa estar autorizado',
            ], 401);
        }

        try {
            $apiClient = new \App\Services\Instagram\InstagramApiClient($accessToken);
            $userInfo = $apiClient->findUserByUsername(
                $username,
                $channel->instagram_id,
                $accessToken,
                $channel
            );

            if (!$userInfo) {
                return response()->json([
                    'error' => 'Contato não encontrado',
                    'message' => "Não foi possível encontrar o usuário '{$username}' no Instagram. Verifique se o username está correto e se a conta é Business ou Creator.",
                ], 404);
            }

            return response()->json([
                'instagram_id' => $userInfo['id'],
                'username' => $userInfo['username'],
                'name' => $userInfo['name'],
                'profile_pic' => $userInfo['profile_pic'],
                'follower_count' => $userInfo['follower_count'],
                'biography' => $userInfo['biography'] ?? null,
                'identifier_instagram' => $userInfo['id'],
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('[CONTACTS] Erro ao buscar contato Instagram por username', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Erro ao buscar contato',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Busca informações de um contato Instagram diretamente da API por Instagram ID
     * 
     * @param Request $request
     * @param string $instagramId Instagram ID do contato
     * @return JsonResponse
     */
    public function getInstagramContact(Request $request, string $instagramId): JsonResponse
    {
        $account = Current::account();
        if (!$account) {
            return response()->json(['error' => 'Account não encontrada'], 404);
        }

        // Busca canal Instagram ativo
        $channel = $this->getInstagramChannel($account, $request->input('inbox_id'));
        if (!$channel) {
            return response()->json([
                'error' => 'Nenhum canal Instagram ativo encontrado',
                'message' => 'É necessário ter pelo menos um inbox Instagram ativo para buscar contatos da API',
            ], 404);
        }

        $accessToken = $channel->getAccessToken();
        if (!$accessToken) {
            return response()->json([
                'error' => 'Token de acesso não disponível',
                'message' => 'O canal Instagram precisa estar autorizado',
            ], 401);
        }

        try {
            $apiClient = new \App\Services\Instagram\InstagramApiClient($accessToken);
            $userInfo = $apiClient->fetchInstagramUser($instagramId, $accessToken, $channel);

            if (!$userInfo) {
                return response()->json([
                    'error' => 'Contato não encontrado',
                    'message' => "Não foi possível encontrar o usuário com ID '{$instagramId}'. O usuário pode não ter interagido com sua conta ainda.",
                ], 404);
            }

            return response()->json([
                'instagram_id' => $userInfo['id'],
                'username' => $userInfo['username'],
                'name' => $userInfo['name'],
                'profile_pic' => $userInfo['profile_pic'],
                'follower_count' => $userInfo['follower_count'],
                'is_user_follow_business' => $userInfo['is_user_follow_business'] ?? null,
                'is_business_follow_user' => $userInfo['is_business_follow_user'] ?? null,
                'is_verified_user' => $userInfo['is_verified_user'] ?? null,
                'identifier_instagram' => $userInfo['id'],
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('[CONTACTS] Erro ao buscar contato Instagram por ID', [
                'instagram_id' => $instagramId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Erro ao buscar contato',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Busca um canal Instagram ativo da account
     * 
     * @param \App\Models\Account $account
     * @param int|null $inboxId ID do inbox específico (opcional)
     * @return \App\Models\Channel\InstagramChannel|null
     */
    protected function getInstagramChannel(\App\Models\Account $account, ?int $inboxId = null): ?\App\Models\Channel\InstagramChannel
    {
        // Se inbox_id foi fornecido, busca o canal desse inbox
        if ($inboxId) {
            $inbox = \App\Models\Inbox::withoutGlobalScopes()
                ->where('id', $inboxId)
                ->where('account_id', $account->id)
                ->where('channel_type', \App\Models\Channel\InstagramChannel::class)
                ->where('is_active', true)
                ->with('channel')
                ->first();

            if ($inbox && $inbox->channel instanceof \App\Models\Channel\InstagramChannel) {
                return $inbox->channel;
            }

            return null;
        }

        // Busca o primeiro inbox Instagram ativo
        $inbox = \App\Models\Inbox::withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->where('channel_type', \App\Models\Channel\InstagramChannel::class)
            ->where('is_active', true)
            ->with('channel')
            ->first();

        if ($inbox && $inbox->channel instanceof \App\Models\Channel\InstagramChannel) {
            return $inbox->channel;
        }

        return null;
    }

    /**
     * Deleta um contato
     * 
     * Deleta o contato e todas as conversas associadas (cascata).
     * Segue o mesmo comportamento do Chatwoot.
     * 
     * IMPORTANTE: Extrai o contact_id diretamente dos parâmetros da rota
     * para evitar problemas com route model binding e global scopes
     * 
     * @param Request $request
     * @param mixed $contact Parâmetro da rota (pode ser ID ou objeto Contact)
     * @return JsonResponse
     */
    public function destroy(Request $request, $contact): JsonResponse
    {
        $account = Current::account();
        if (!$account) {
            return response()->json(['error' => 'Account não encontrada'], 404);
        }

        // Extrai o contact_id diretamente dos parâmetros da rota
        $routeParams = $request->route()->parameters();
        $contactIdFromRoute = $routeParams['contact'] ?? null;
        
        // Se o parâmetro da rota é um objeto, pega o ID dele
        // Se é um número, usa diretamente
        if ($contactIdFromRoute instanceof Contact) {
            $contactId = $contactIdFromRoute->id;
        } elseif (is_numeric($contactIdFromRoute)) {
            $contactId = (int) $contactIdFromRoute;
        } else {
            // Fallback: tenta extrair do objeto $contact
            $contactId = $contact instanceof Contact ? $contact->id : (int) $contact;
        }

        // Busca o contato garantindo que pertence à account atual
        $contact = Contact::withoutGlobalScopes()
            ->where('id', $contactId)
            ->where('account_id', $account->id)
            ->withCount(['conversations', 'contactInboxes'])
            ->firstOrFail();
        
        // Log para debug
        \Illuminate\Support\Facades\Log::info('[CONTACT DELETE] Deletando contato', [
            'contact_id' => $contact->id,
            'contact_name' => $contact->name,
            'account_id' => $account->id,
            'contact_id_from_route' => $contactId,
            'conversations_count' => $contact->conversations_count,
            'contact_inboxes_count' => $contact->contact_inboxes_count,
        ]);

        // Deleta o contato (as conversas e contact_inboxes serão deletados automaticamente via boot)
        DB::transaction(function () use ($contact) {
            $contact->delete();
        });

        return response()->json([
            'message' => 'Contato deletado com sucesso',
            'deleted_conversations' => $contact->conversations_count ?? 0,
        ]);
    }
}

<?php

namespace App\Services\Instagram;

use App\Models\Account;
use App\Models\Channel\InstagramChannel;
use App\Models\Inbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Serviço ChannelCreationService
 * 
 * Cria canal Instagram e inbox associado.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\Instagram
 */
class ChannelCreationService
{
    protected Account $account;
    protected array $userDetails;
    protected array $tokenData;
    protected ?string $webhookUrl;
    protected ?int $inboxId;

    /**
     * Construtor
     * 
     * @param Account $account
     * @param array $userDetails Detalhes do usuário Instagram
     * @param array $tokenData Dados do token (access_token, expires_in)
     * @param string|null $webhookUrl URL do webhook para o frontend (opcional) - será salvo no inbox
     * @param int|null $inboxId ID do inbox existente para associar ao channel (opcional)
     */
    public function __construct(Account $account, array $userDetails, array $tokenData, ?string $webhookUrl = null, ?int $inboxId = null)
    {
        $this->account = $account;
        $this->userDetails = $userDetails;
        $this->tokenData = $tokenData;
        $this->webhookUrl = $webhookUrl;
        $this->inboxId = $inboxId;
    }

    /**
     * Executa a criação do canal
     * 
     * @return InstagramChannel
     * @throws \Exception
     */
    public function perform(): InstagramChannel
    {
        $this->validateParameters();

        // Busca channel existente pelo Instagram ID (pode estar em outro inbox)
        $existingChannelWithSameInstagramId = $this->findExistingChannel();

        // Se tiver inbox_id, busca inbox existente para associar ao channel
        $existingInbox = null;
        if ($this->inboxId) {
            Log::info('[INSTAGRAM Channel Creation] Buscando inbox existente para associar', [
                'inbox_id' => $this->inboxId,
                'account_id' => $this->account->id,
                'channel_type' => InstagramChannel::class,
            ]);
            
            // Busca o inbox sem filtro de channel_type primeiro (mais robusto)
            // Usa withoutGlobalScopes para garantir que não há interferência do HasAccountScope
            $inboxWithoutTypeFilter = Inbox::withoutGlobalScopes()
                ->where('id', $this->inboxId)
                ->where('account_id', $this->account->id)
                ->first();
            
            if (!$inboxWithoutTypeFilter) {
                Log::error('[INSTAGRAM Channel Creation] Inbox não encontrado (sem filtro de tipo)', [
                    'inbox_id' => $this->inboxId,
                    'account_id' => $this->account->id,
                ]);
                throw new \Exception('Inbox não encontrado');
            }
            
            Log::info('[INSTAGRAM Channel Creation] Inbox encontrado (sem filtro de tipo)', [
                'inbox_id' => $inboxWithoutTypeFilter->id,
                'channel_type' => $inboxWithoutTypeFilter->channel_type,
                'expected_channel_type' => InstagramChannel::class,
            ]);
            
            // Valida se o channel_type é Instagram (com tolerância a diferenças de escape)
            $expectedType = InstagramChannel::class;
            $actualType = $inboxWithoutTypeFilter->channel_type;
            
            // Compara diretamente e também com normalização
            $isMatch = $actualType === $expectedType 
                || trim($actualType) === trim($expectedType)
                || str_replace(['\\\\', '\\'], ['\\', '\\'], $actualType) === str_replace(['\\\\', '\\'], ['\\', '\\'], $expectedType);
            
            if (!$isMatch) {
                Log::error('[INSTAGRAM Channel Creation] Inbox encontrado mas channel_type não corresponde', [
                    'inbox_id' => $inboxWithoutTypeFilter->id,
                    'expected_type' => $expectedType,
                    'actual_type' => $actualType,
                ]);
                throw new \Exception('Inbox não é do tipo Instagram');
            }
            
            // Usa o inbox encontrado
            $existingInbox = $inboxWithoutTypeFilter;
            
            // LOGICA DE RESOLUÇÃO DE CONFLITO:
            // Se já existe um canal com este Instagram ID (ex: em outro inbox)
            // E o inbox solicitado (Inbox 2) NÃO está ligado a ele (está ligado a um temp ou nada)
            if ($existingChannelWithSameInstagramId && $existingInbox->channel_id !== $existingChannelWithSameInstagramId->id) {
                Log::info('[INSTAGRAM Channel Creation] CONFLITO DETECTADO: Instagram ID já existe em outro canal', [
                    'existing_channel_id' => $existingChannelWithSameInstagramId->id,
                    'target_inbox_id' => $existingInbox->id,
                    'target_inbox_current_channel_id' => $existingInbox->channel_id,
                ]);

                // 1. Se o inbox alvo tem um canal temporário, deleta-o para limpar
                if ($existingInbox->channel_id) {
                    $currentChannel = InstagramChannel::find($existingInbox->channel_id);
                    if ($currentChannel && str_starts_with($currentChannel->instagram_id, 'temp_')) {
                        Log::info('[INSTAGRAM Channel Creation] Deletando canal temporário do inbox alvo', [
                            'temp_channel_id' => $currentChannel->id
                        ]);
                        $currentChannel->delete();
                    }
                }

                // 2. Move o canal existente para o inbox alvo (desassocia do antigo)
                $this->moveChannelToInbox($existingChannelWithSameInstagramId, $existingInbox);

                // 3. Atualiza tokens do canal
                return $this->updateChannel($existingChannelWithSameInstagramId);
            }
            
            Log::info('[INSTAGRAM Channel Creation] Inbox existente encontrado', [
                'inbox_id' => $existingInbox->id,
                'channel_id' => $existingInbox->channel_id,
                'channel_type' => $existingInbox->channel_type,
            ]);
            
            // Se o inbox já tem channel_id (e não é 0), verifica se é temporário
            if ($existingInbox->channel_id && $existingInbox->channel_id > 0) {
                $existingChannel = InstagramChannel::find($existingInbox->channel_id);
                if ($existingChannel) {
                    // Verifica se é um channel temporário (criado automaticamente)
                    $isTemporary = str_starts_with($existingChannel->instagram_id, 'temp_');
                    
                    if ($isTemporary) {
                        Log::info('[INSTAGRAM Channel Creation] Atualizando channel temporário com dados reais do OAuth', [
                            'channel_id' => $existingChannel->id,
                            'inbox_id' => $existingInbox->id,
                        ]);
                        // Atualiza o channel temporário com os dados reais
                        return $this->updateTemporaryChannel($existingChannel);
                    } else {
                        // Channel já tem dados reais, apenas atualiza tokens
                        Log::info('[INSTAGRAM Channel Creation] Atualizando channel existente com novos tokens', [
                            'channel_id' => $existingChannel->id,
                        ]);
                        return $this->updateChannel($existingChannel);
                    }
                }
            }
        }
        
        if ($existingChannelWithSameInstagramId) {
            // Atualiza canal existente
            return $this->updateChannel($existingChannelWithSameInstagramId);
        }

        // Se tiver inbox existente, cria novo channel e associa
        if ($existingInbox) {
            Log::info('[INSTAGRAM Channel Creation] Criando channel e associando ao inbox existente', [
                'inbox_id' => $existingInbox->id,
            ]);
            return $this->createChannelAndAssociateInbox($existingInbox);
        }

        Log::info('[INSTAGRAM Channel Creation] Criando channel e inbox novo');
        return $this->createChannelWithInbox();
    }

    /**
     * Valida parâmetros obrigatórios
     * 
     * @return void
     * @throws \Exception
     */
    protected function validateParameters(): void
    {
        if (empty($this->account)) {
            throw new \Exception('Account is required');
        }

        if (empty($this->userDetails['user_id'] ?? $this->userDetails['id'])) {
            throw new \Exception('Instagram user ID is required');
        }

        if (empty($this->tokenData['access_token'])) {
            throw new \Exception('Access token is required');
        }
    }

    /**
     * Busca canal existente pelo Instagram ID
     * 
     * @return InstagramChannel|null
     */
    protected function findExistingChannel(): ?InstagramChannel
    {
        $instagramId = (string) ($this->userDetails['user_id'] ?? $this->userDetails['id']);
        
        // Busca sem global scope para encontrar channels órfãos (que podem ter ficado no banco)
        // mas filtra por account_id para garantir que é da mesma conta
        return InstagramChannel::withoutGlobalScope(\App\Models\Concerns\HasAccountScope::class)
            ->where('instagram_id', $instagramId)
            ->where('account_id', $this->account->id)
            ->first();
    }

    /**
     * Move um canal existente para um novo inbox
     * Desassocia do inbox antigo e associa ao novo
     * 
     * @param InstagramChannel $channel
     * @param Inbox $targetInbox
     * @return void
     */
    protected function moveChannelToInbox(InstagramChannel $channel, Inbox $targetInbox): void
    {
        // 1. Encontrar quem está usando este canal atualmente
        $currentOwnerInbox = Inbox::withoutGlobalScopes()
            ->where('channel_type', InstagramChannel::class)
            ->where('channel_id', $channel->id)
            ->first();
            
        if ($currentOwnerInbox && $currentOwnerInbox->id !== $targetInbox->id) {
            Log::info('[INSTAGRAM Channel Creation] Desassociando inbox antigo', [
                'channel_id' => $channel->id,
                'old_inbox_id' => $currentOwnerInbox->id
            ]);
            $currentOwnerInbox->update(['channel_id' => null, 'is_active' => false]);
        }
        
        // 2. Associar novo inbox
        Log::info('[INSTAGRAM Channel Creation] Associando novo inbox', [
            'channel_id' => $channel->id,
            'new_inbox_id' => $targetInbox->id
        ]);
        
        $targetInbox->update([
            'channel_id' => $channel->id,
            'channel_type' => InstagramChannel::class,
            'is_active' => true,
            // Opcional: Atualizar nome se necessário
            'name' => $this->userDetails['username'] ?? $targetInbox->name
        ]);
        
        // Atualiza relação no objeto channel
        $channel->setRelation('inbox', $targetInbox);
    }

    /**
     * Atualiza canal existente
     * 
     * @param InstagramChannel $channel
     * @return InstagramChannel
     */
    protected function updateChannel(InstagramChannel $channel): InstagramChannel
    {
        $expiresAt = \Carbon\Carbon::now()->addSeconds($this->tokenData['expires_in'] ?? 5184000);

        $updateData = [
            'access_token' => $this->tokenData['access_token'],
            'expires_at' => $expiresAt,
        ];

        // Atualiza webhook_url se fornecido
        if ($this->webhookUrl !== null) {
            $updateData['webhook_url'] = $this->webhookUrl;
        }

        $channel->update($updateData);

        // IMPORTANTE: Carrega o relacionamento inbox se não estiver carregado
        if (!$channel->relationLoaded('inbox')) {
            $channel->load('inbox');
        }
        
        // Se o relacionamento polimórfico não funcionou, busca manualmente
        if (!$channel->inbox) {
            Log::warning('[INSTAGRAM Channel Creation] Relacionamento inbox não encontrado em updateChannel, buscando manualmente', [
                'channel_id' => $channel->id,
                'account_id' => $this->account->id,
            ]);
            
            $inbox = Inbox::withoutGlobalScope(\App\Models\Concerns\HasAccountScope::class)
                ->where('channel_type', InstagramChannel::class)
                ->where('channel_id', $channel->id)
                ->where('account_id', $this->account->id)
                ->first();
            
            if ($inbox) {
                $channel->setRelation('inbox', $inbox);
                Log::info('[INSTAGRAM Channel Creation] Inbox encontrado manualmente em updateChannel', [
                    'channel_id' => $channel->id,
                    'inbox_id' => $inbox->id,
                ]);
            } elseif ($this->inboxId) {
                // Se ainda não encontrar e tiver inbox_id, busca pelo inbox_id
                $inboxById = Inbox::withoutGlobalScope(\App\Models\Concerns\HasAccountScope::class)
                    ->where('id', $this->inboxId)
                    ->where('account_id', $this->account->id)
                    ->where('channel_type', InstagramChannel::class)
                    ->first();
                
                if ($inboxById) {
                    // Atualiza o inbox para apontar para este channel
                    $inboxById->update(['channel_id' => $channel->id]);
                    $inboxById->refresh();
                    $channel->setRelation('inbox', $inboxById);
                    Log::info('[INSTAGRAM Channel Creation] Inbox encontrado pelo inbox_id em updateChannel e atualizado', [
                        'channel_id' => $channel->id,
                        'inbox_id' => $inboxById->id,
                    ]);
                }
            }
        }

        // Atualiza nome do inbox se username mudou
        $username = $this->userDetails['username'] ?? null;
        if ($username && $channel->inbox) {
            $channel->inbox->update(['name' => $username]);
        }

        return $channel;
    }

    /**
     * Atualiza channel temporário com dados reais do OAuth
     * 
     * @param InstagramChannel $channel
     * @return InstagramChannel
     */
    protected function updateTemporaryChannel(InstagramChannel $channel): InstagramChannel
    {
        $instagramId = (string) ($this->userDetails['user_id'] ?? $this->userDetails['id']);
        $expiresAt = \Carbon\Carbon::now()->addSeconds($this->tokenData['expires_in'] ?? 5184000);

        $updateData = [
            'instagram_id' => $instagramId, // Substitui o ID temporário pelo real
            'access_token' => $this->tokenData['access_token'],
            'expires_at' => $expiresAt,
        ];

        // Atualiza webhook_url se fornecido
        if ($this->webhookUrl !== null) {
            $updateData['webhook_url'] = $this->webhookUrl;
        }

        $channel->update($updateData);

        // IMPORTANTE: Carrega o relacionamento inbox se não estiver carregado
        if (!$channel->relationLoaded('inbox')) {
            $channel->load('inbox');
        }
        
        // Se o relacionamento polimórfico não funcionou, busca manualmente
        if (!$channel->inbox) {
            Log::warning('[INSTAGRAM Channel Creation] Relacionamento inbox não encontrado, buscando manualmente', [
                'channel_id' => $channel->id,
                'account_id' => $this->account->id,
            ]);
            
            $inbox = Inbox::withoutGlobalScope(\App\Models\Concerns\HasAccountScope::class)
                ->where('channel_type', InstagramChannel::class)
                ->where('channel_id', $channel->id)
                ->where('account_id', $this->account->id)
                ->first();
            
            if ($inbox) {
                $channel->setRelation('inbox', $inbox);
                Log::info('[INSTAGRAM Channel Creation] Inbox encontrado manualmente e relacionamento forçado', [
                    'channel_id' => $channel->id,
                    'inbox_id' => $inbox->id,
                ]);
            } else {
                // Se ainda não encontrar e tiver inbox_id, busca pelo inbox_id
                if ($this->inboxId) {
                    $inboxById = Inbox::withoutGlobalScope(\App\Models\Concerns\HasAccountScope::class)
                        ->where('id', $this->inboxId)
                        ->where('account_id', $this->account->id)
                        ->where('channel_type', InstagramChannel::class)
                        ->first();
                    
                    if ($inboxById) {
                        // Atualiza o inbox para apontar para este channel
                        $inboxById->update(['channel_id' => $channel->id]);
                        $inboxById->refresh();
                        $channel->setRelation('inbox', $inboxById);
                        Log::info('[INSTAGRAM Channel Creation] Inbox encontrado pelo inbox_id e atualizado', [
                            'channel_id' => $channel->id,
                            'inbox_id' => $inboxById->id,
                        ]);
                    }
                }
            }
        }

        // Atualiza nome do inbox com username real
        $username = $this->userDetails['username'] ?? null;
        if ($username && $channel->inbox) {
            $channel->inbox->update([
                'name' => $username,
                'is_active' => true, // Ativa o inbox agora que tem credenciais reais
            ]);
        }

        Log::info('[INSTAGRAM Channel Creation] Channel temporário atualizado com dados reais', [
            'channel_id' => $channel->id,
            'instagram_id' => $instagramId,
            'inbox_id' => $channel->inbox?->id,
            'has_inbox_relation' => $channel->inbox !== null,
        ]);

        return $channel;
    }

    /**
     * Cria canal e inbox em transação
     * 
     * @return InstagramChannel
     */
    protected function createChannelWithInbox(): InstagramChannel
    {
        return DB::transaction(function () {
            $channel = $this->buildChannel();
            $inbox = $this->createInbox($channel);
            
            // IMPORTANTE: Recarrega o canal para garantir que o relacionamento inbox está disponível
            $channel->refresh();
            
            // Carrega o relacionamento inbox
            $channel->load('inbox');
            
            // Valida que o inbox foi criado e relacionado corretamente
            if (!$channel->inbox) {
                Log::error('[INSTAGRAM Channel Creation] Inbox não foi relacionado ao canal após criação', [
                    'channel_id' => $channel->id,
                    'inbox_id' => $inbox->id,
                    'inbox_channel_type' => $inbox->channel_type,
                    'inbox_channel_id' => $inbox->channel_id,
                    'expected_channel_type' => InstagramChannel::class,
                    'instagram_id' => $channel->instagram_id,
                ]);
                throw new \Exception('Inbox não foi relacionado ao canal após criação');
            }
            
            Log::info('[INSTAGRAM Channel Creation] Canal e inbox criados', [
                'channel_id' => $channel->id,
                'inbox_id' => $inbox->id,
                'inbox_from_relation' => $channel->inbox->id,
                'instagram_id' => $channel->instagram_id,
            ]);
            
            return $channel;
        });
    }

    /**
     * Constrói o canal Instagram
     * 
     * @return InstagramChannel
     */
    protected function buildChannel(): InstagramChannel
    {
        $instagramId = (string) ($this->userDetails['user_id'] ?? $this->userDetails['id']);
        $expiresAt = \Carbon\Carbon::now()->addSeconds($this->tokenData['expires_in'] ?? 5184000);

        $channelData = [
            'account_id' => $this->account->id,
            'instagram_id' => $instagramId,
            'access_token' => $this->tokenData['access_token'],
            'expires_at' => $expiresAt,
        ];

        // Adiciona webhook_url se fornecido
        if ($this->webhookUrl !== null) {
            $channelData['webhook_url'] = $this->webhookUrl;
        }

        return InstagramChannel::create($channelData);
    }

    /**
     * Cria inbox associado ao canal
     * 
     * @param InstagramChannel $channel
     * @return Inbox
     */
    protected function createInbox(InstagramChannel $channel): Inbox
    {
        $inboxName = $this->userDetails['username'] ?? 'Instagram';

        $inbox = Inbox::create([
            'account_id' => $this->account->id,
            'name' => $inboxName,
            'channel_type' => InstagramChannel::class,
            'channel_id' => $channel->id,
            'timezone' => 'America/Sao_Paulo', // Default
            'is_active' => \App\Support\AppConfigHelper::isConfigured('instagram'),
        ]);

        return $inbox;
    }

    /**
     * Cria canal e associa ao inbox existente
     * 
     * @param Inbox $existingInbox
     * @return InstagramChannel
     */
    protected function createChannelAndAssociateInbox(Inbox $existingInbox): InstagramChannel
    {
        return DB::transaction(function () use ($existingInbox) {
            $channel = $this->buildChannel();
            
            // Atualiza nome do inbox se username mudou (antes de associar channel_id)
            $username = $this->userDetails['username'] ?? null;
            $updateData = [
                'channel_id' => $channel->id,
                'is_active' => \App\Support\AppConfigHelper::isConfigured('instagram'),
            ];
            
            if ($username && $existingInbox->name !== $username) {
                $updateData['name'] = $username;
            }
            
            // Associa o inbox existente ao channel
            $existingInbox->update($updateData);
            
            // IMPORTANTE: Recarrega o inbox para garantir que está atualizado
            $existingInbox->refresh();
            
            // Verifica se o inbox foi atualizado corretamente
            $existingInbox->refresh();
            
            Log::info('[INSTAGRAM Channel Creation] Inbox atualizado', [
                'inbox_id' => $existingInbox->id,
                'channel_id' => $existingInbox->channel_id,
                'channel_type' => $existingInbox->channel_type,
            ]);
            
            // IMPORTANTE: Força o relacionamento usando o inbox que acabamos de atualizar
            // O relacionamento polimórfico pode não estar disponível imediatamente após a atualização
            // Então forçamos o relacionamento usando o objeto que sabemos que foi atualizado corretamente
            $channel->setRelation('inbox', $existingInbox);
            
            // Verifica se o relacionamento foi definido corretamente
            if (!$channel->inbox || $channel->inbox->id !== $existingInbox->id) {
                // Se não funcionou, tenta buscar diretamente do banco
                Log::warning('[INSTAGRAM Channel Creation] Relacionamento forçado não funcionou, buscando diretamente no banco', [
                    'channel_id' => $channel->id,
                    'expected_inbox_id' => $existingInbox->id,
                ]);
                
                $inboxFromDb = Inbox::where('channel_type', InstagramChannel::class)
                    ->where('channel_id', $channel->id)
                    ->where('account_id', $this->account->id)
                    ->where('id', $existingInbox->id)
                    ->first();
                
                if ($inboxFromDb && $inboxFromDb->channel_id === $channel->id) {
                    // Força o relacionamento no model com o inbox encontrado
                    $channel->setRelation('inbox', $inboxFromDb);
                    Log::info('[INSTAGRAM Channel Creation] Inbox encontrado diretamente no banco e relacionamento forçado', [
                        'channel_id' => $channel->id,
                        'inbox_id' => $inboxFromDb->id,
                    ]);
                } else {
                    // Se ainda não encontrar, usa o inbox que acabamos de atualizar (último recurso)
                    $channel->setRelation('inbox', $existingInbox);
                    Log::warning('[INSTAGRAM Channel Creation] Usando inbox atualizado como relacionamento (último recurso)', [
                        'channel_id' => $channel->id,
                        'inbox_id' => $existingInbox->id,
                        'inbox_channel_id' => $existingInbox->channel_id,
                    ]);
                }
            }
            
            // Valida que o inbox foi associado corretamente
            if (!$channel->inbox || $channel->inbox->id !== $existingInbox->id) {
                Log::error('[INSTAGRAM Channel Creation] Inbox não foi associado ao canal corretamente', [
                    'channel_id' => $channel->id,
                    'expected_inbox_id' => $existingInbox->id,
                    'inbox_from_relation' => $channel->inbox?->id,
                    'channel_type' => InstagramChannel::class,
                    'inbox_channel_type' => $existingInbox->channel_type,
                    'inbox_channel_id' => $existingInbox->channel_id,
                ]);
                throw new \Exception('Inbox não foi associado ao canal corretamente');
            }
            
            // IMPORTANTE: Garante que o relacionamento está carregado antes de retornar
            // Recarrega o inbox para garantir que está atualizado
            $existingInbox->refresh();
            
            // Verifica se o update funcionou
            if ($existingInbox->channel_id != $channel->id) {
                Log::error('[INSTAGRAM Channel Creation] Inbox não foi atualizado corretamente com channel_id', [
                    'inbox_id' => $existingInbox->id,
                    'expected_channel_id' => $channel->id,
                    'actual_channel_id' => $existingInbox->channel_id,
                ]);
                throw new \Exception('Inbox não foi atualizado corretamente com channel_id');
            }
            
            // Força o relacionamento no channel usando o inbox atualizado
            $channel->setRelation('inbox', $existingInbox);
            
            // Tenta também carregar pelo relacionamento normal (pode não funcionar imediatamente)
            try {
                $channel->load('inbox');
            } catch (\Exception $e) {
                Log::warning('[INSTAGRAM Channel Creation] Erro ao carregar relacionamento inbox, usando relacionamento forçado', [
                    'error' => $e->getMessage(),
                ]);
            }
            
            // Validação final: garante que o relacionamento está disponível
            if (!$channel->inbox || $channel->inbox->id !== $existingInbox->id) {
                // Busca novamente do banco como último recurso
                $finalInbox = Inbox::where('id', $existingInbox->id)
                    ->where('channel_id', $channel->id)
                    ->where('account_id', $this->account->id)
                    ->first();
                
                if ($finalInbox && $finalInbox->channel_id == $channel->id) {
                    $channel->setRelation('inbox', $finalInbox);
                    Log::info('[INSTAGRAM Channel Creation] Inbox encontrado diretamente no banco antes de retornar', [
                        'channel_id' => $channel->id,
                        'inbox_id' => $finalInbox->id,
                    ]);
                } else {
                    // Força novamente com o inbox atualizado que sabemos que está correto
                    $channel->setRelation('inbox', $existingInbox);
                    Log::warning('[INSTAGRAM Channel Creation] Forçando relacionamento usando inbox atualizado antes de retornar', [
                        'channel_id' => $channel->id,
                        'inbox_id' => $existingInbox->id,
                    ]);
                }
            }
            
            Log::info('[INSTAGRAM Channel Creation] Canal criado e associado ao inbox existente', [
                'channel_id' => $channel->id,
                'inbox_id' => $existingInbox->id,
                'inbox_from_relation' => $channel->inbox?->id ?? 'null',
                'inbox_channel_id_in_db' => $existingInbox->channel_id,
                'has_relation' => $channel->relationLoaded('inbox'),
                'instagram_id' => $channel->instagram_id,
            ]);
            
            return $channel;
        });
    }
}

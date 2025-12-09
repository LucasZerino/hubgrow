<?php

namespace Tests\Feature\Api\V1\Accounts;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Inbox;
use App\Models\User;
use App\Support\Current;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testes de Inboxes
 * 
 * @package Tests\Feature\Api\V1\Accounts
 */
class InboxesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Account $account;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Cria usuário e account
        $this->account = Account::factory()->create();
        $this->user = User::factory()->create();
        
        // Vincula usuário à account
        AccountUser::factory()->create([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'role' => AccountUser::ROLE_ADMINISTRATOR,
        ]);

        // Cria token
        $this->token = $this->user->createToken('test-token')->plainTextToken;

        // Define contexto
        Current::setAccount($this->account);
        Current::setUser($this->user);
    }

    /**
     * Testa listar inboxes
     */
    public function test_can_list_inboxes(): void
    {
        Inbox::factory()->count(3)->create([
            'account_id' => $this->account->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->getJson("/api/v1/accounts/{$this->account->id}/inboxes");

        $response->assertStatus(200);
        
        // Verifica que retorna array de inboxes
        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertCount(3, $data);
    }

    /**
     * Testa criar inbox (channel é criado automaticamente)
     */
    public function test_can_create_inbox(): void
    {
        $data = [
            'name' => 'Meu Inbox',
            'channel_type' => 'App\\Models\\Channel\\WebWidgetChannel',
            'timezone' => 'America/Sao_Paulo',
        ];

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->postJson("/api/v1/accounts/{$this->account->id}/inboxes", $data);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'id',
                     'name',
                     'account_id',
                     'channel_type',
                     'channel_id',
                     'channel',
                 ]);

        $responseData = $response->json();
        
        // Verifica que o inbox foi criado
        $this->assertDatabaseHas('inboxes', [
            'name' => 'Meu Inbox',
            'account_id' => $this->account->id,
            'channel_id' => $responseData['channel_id'],
        ]);
        
        // Verifica que o channel foi criado automaticamente
        $this->assertDatabaseHas('web_widget_channels', [
            'id' => $responseData['channel_id'],
            'account_id' => $this->account->id,
        ]);
    }

    /**
     * Testa criar inbox além do limite
     */
    public function test_cannot_create_inbox_beyond_limit(): void
    {
        // Define limite de 1 inbox
        $this->account->setLimit('inboxes', 1);

        // Cria 1 inbox
        Inbox::factory()->create([
            'account_id' => $this->account->id,
        ]);

        // Tenta criar outro (deve falhar)
        $data = [
            'name' => 'Segundo Inbox',
            'channel_type' => 'App\\Models\\Channel\\WebWidgetChannel',
        ];

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->postJson("/api/v1/accounts/{$this->account->id}/inboxes", $data);

        $response->assertStatus(402)
                 ->assertJsonStructure(['error', 'message', 'usage']);
    }

    /**
     * Testa criar inbox Instagram (channel placeholder)
     */
    public function test_can_create_instagram_inbox(): void
    {
        $data = [
            'name' => 'Meu Inbox Instagram',
            'channel_type' => 'App\\Models\\Channel\\InstagramChannel',
            'webhook_url' => 'https://example.com/webhook',
        ];

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->postJson("/api/v1/accounts/{$this->account->id}/inboxes", $data);

        $response->assertStatus(201);
        
        $responseData = $response->json();
        
        // Verifica que o inbox foi criado como inativo
        $this->assertEquals(false, $responseData['is_active']);
        
        // Verifica que o channel foi criado automaticamente
        $this->assertDatabaseHas('instagram_channels', [
            'id' => $responseData['channel_id'],
            'account_id' => $this->account->id,
        ]);
    }

    /**
     * Testa mostrar inbox específico
     */
    public function test_can_show_inbox(): void
    {
        $inbox = Inbox::factory()->create([
            'account_id' => $this->account->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->getJson("/api/v1/accounts/{$this->account->id}/inboxes/{$inbox->id}");

        $response->assertStatus(200)
                 ->assertJson([
                     'id' => $inbox->id,
                     'name' => $inbox->name,
                 ]);
    }

    /**
     * Testa atualizar inbox
     */
    public function test_can_update_inbox(): void
    {
        $inbox = Inbox::factory()->create([
            'account_id' => $this->account->id,
        ]);

        $data = [
            'name' => 'Nome Atualizado',
            'timezone' => 'UTC',
        ];

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->putJson("/api/v1/accounts/{$this->account->id}/inboxes/{$inbox->id}", $data);

        $response->assertStatus(200)
                 ->assertJson([
                     'name' => 'Nome Atualizado',
                 ]);

        $this->assertDatabaseHas('inboxes', [
            'id' => $inbox->id,
            'name' => 'Nome Atualizado',
        ]);
    }

    /**
     * Testa deletar inbox
     */
    public function test_can_delete_inbox(): void
    {
        $inbox = Inbox::factory()->create([
            'account_id' => $this->account->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->deleteJson("/api/v1/accounts/{$this->account->id}/inboxes/{$inbox->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('inboxes', [
            'id' => $inbox->id,
        ]);
    }

    /**
     * Testa acesso negado para account diferente
     */
    public function test_cannot_access_other_account_inboxes(): void
    {
        $otherAccount = Account::factory()->create();
        $inbox = Inbox::factory()->create([
            'account_id' => $otherAccount->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->getJson("/api/v1/accounts/{$otherAccount->id}/inboxes/{$inbox->id}");

        $response->assertStatus(403);
    }
}

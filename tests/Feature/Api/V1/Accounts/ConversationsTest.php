<?php

namespace Tests\Feature\Api\V1\Accounts;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\User;
use App\Support\Current;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testes de Conversations
 * 
 * @package Tests\Feature\Api\V1\Accounts
 */
class ConversationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Account $account;
    private Inbox $inbox;
    private Contact $contact;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->create();
        $this->user = User::factory()->create();
        $this->inbox = Inbox::factory()->create(['account_id' => $this->account->id]);
        $this->contact = Contact::factory()->create(['account_id' => $this->account->id]);
        
        AccountUser::factory()->create([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'role' => AccountUser::ROLE_ADMINISTRATOR,
        ]);

        $this->token = $this->user->createToken('test-token')->plainTextToken;

        Current::setAccount($this->account);
        Current::setUser($this->user);
    }

    /**
     * Testa listar conversas
     */
    public function test_can_list_conversations(): void
    {
        Conversation::factory()->count(3)->create([
            'account_id' => $this->account->id,
            'inbox_id' => $this->inbox->id,
            'contact_id' => $this->contact->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->getJson("/api/v1/accounts/{$this->account->id}/conversations");

        $response->assertStatus(200);
        
        // Verifica estrutura de paginação
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'account_id', 'inbox_id', 'contact_id'],
            ],
        ]);
    }

    /**
     * Testa criar conversa
     */
    public function test_can_create_conversation(): void
    {
        $data = [
            'inbox_id' => $this->inbox->id,
            'contact_id' => $this->contact->id,
            'status' => Conversation::STATUS_OPEN,
        ];

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->postJson("/api/v1/accounts/{$this->account->id}/conversations", $data);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'id',
                     'account_id',
                     'inbox_id',
                     'contact_id',
                     'status',
                 ]);

        $this->assertDatabaseHas('conversations', [
            'account_id' => $this->account->id,
            'inbox_id' => $this->inbox->id,
            'contact_id' => $this->contact->id,
        ]);
    }

    /**
     * Testa mostrar conversa específica
     */
    public function test_can_show_conversation(): void
    {
        $conversation = Conversation::factory()->create([
            'account_id' => $this->account->id,
            'inbox_id' => $this->inbox->id,
            'contact_id' => $this->contact->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->getJson("/api/v1/accounts/{$this->account->id}/conversations/{$conversation->id}");

        $response->assertStatus(200)
                 ->assertJson([
                     'id' => $conversation->id,
                 ]);
    }

    /**
     * Testa atualizar conversa
     */
    public function test_can_update_conversation(): void
    {
        $conversation = Conversation::factory()->create([
            'account_id' => $this->account->id,
            'inbox_id' => $this->inbox->id,
            'contact_id' => $this->contact->id,
        ]);

        $data = [
            'status' => Conversation::STATUS_RESOLVED,
            'priority' => Conversation::PRIORITY_HIGH,
        ];

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->putJson("/api/v1/accounts/{$this->account->id}/conversations/{$conversation->id}", $data);

        $response->assertStatus(200)
                 ->assertJson([
                     'status' => Conversation::STATUS_RESOLVED,
                     'priority' => Conversation::PRIORITY_HIGH,
                 ]);
    }

    /**
     * Testa toggle status da conversa
     */
    public function test_can_toggle_conversation_status(): void
    {
        $conversation = Conversation::factory()->create([
            'account_id' => $this->account->id,
            'inbox_id' => $this->inbox->id,
            'contact_id' => $this->contact->id,
            'status' => Conversation::STATUS_OPEN,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->postJson("/api/v1/accounts/{$this->account->id}/conversations/{$conversation->id}/toggle_status", [
                             'status' => Conversation::STATUS_RESOLVED,
                         ]);

        $response->assertStatus(200)
                 ->assertJson([
                     'status' => Conversation::STATUS_RESOLVED,
                 ]);

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'status' => Conversation::STATUS_RESOLVED,
        ]);
    }

    /**
     * Testa deletar conversa
     */
    public function test_can_delete_conversation(): void
    {
        $conversation = Conversation::factory()->create([
            'account_id' => $this->account->id,
            'inbox_id' => $this->inbox->id,
            'contact_id' => $this->contact->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->deleteJson("/api/v1/accounts/{$this->account->id}/conversations/{$conversation->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('conversations', [
            'id' => $conversation->id,
        ]);
    }
}

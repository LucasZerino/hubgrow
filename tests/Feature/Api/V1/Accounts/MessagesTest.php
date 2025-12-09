<?php

namespace Tests\Feature\Api\V1\Accounts;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Message;
use App\Models\User;
use App\Support\Current;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testes de Messages
 * 
 * @package Tests\Feature\Api\V1\Accounts
 */
class MessagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Account $account;
    private Inbox $inbox;
    private Contact $contact;
    private Conversation $conversation;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->create();
        $this->user = User::factory()->create();
        $this->inbox = Inbox::factory()->create(['account_id' => $this->account->id]);
        $this->contact = Contact::factory()->create(['account_id' => $this->account->id]);
        $this->conversation = Conversation::factory()->create([
            'account_id' => $this->account->id,
            'inbox_id' => $this->inbox->id,
            'contact_id' => $this->contact->id,
        ]);
        
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
     * Testa listar mensagens de uma conversa
     */
    public function test_can_list_messages(): void
    {
        Message::factory()->count(5)->create([
            'account_id' => $this->account->id,
            'inbox_id' => $this->inbox->id,
            'conversation_id' => $this->conversation->id,
            'sender_id' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->getJson("/api/v1/accounts/{$this->account->id}/conversations/{$this->conversation->id}/messages");

        $response->assertStatus(200);
        
        // Verifica estrutura de paginação
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'content', 'conversation_id'],
            ],
        ]);
    }

    /**
     * Testa criar mensagem
     */
    public function test_can_create_message(): void
    {
        $data = [
            'content' => 'Olá! Como posso ajudar?',
            'content_type' => Message::CONTENT_TYPE_TEXT,
            'message_type' => Message::TYPE_OUTGOING,
        ];

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->postJson("/api/v1/accounts/{$this->account->id}/conversations/{$this->conversation->id}/messages", $data);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'id',
                     'content',
                     'content_type',
                     'message_type',
                     'sender_id',
                 ]);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'content' => 'Olá! Como posso ajudar?',
            'sender_id' => $this->user->id,
        ]);
    }

    /**
     * Testa atualizar mensagem
     */
    public function test_can_update_message(): void
    {
        $message = Message::factory()->create([
            'account_id' => $this->account->id,
            'inbox_id' => $this->inbox->id,
            'conversation_id' => $this->conversation->id,
            'sender_id' => $this->user->id,
            'status' => Message::STATUS_SENT,
        ]);

        $data = [
            'status' => Message::STATUS_DELIVERED,
        ];

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->putJson("/api/v1/accounts/{$this->account->id}/conversations/{$this->conversation->id}/messages/{$message->id}", $data);

        $response->assertStatus(200)
                 ->assertJson([
                     'status' => Message::STATUS_DELIVERED,
                 ]);
    }

    /**
     * Testa deletar mensagem
     */
    public function test_can_delete_message(): void
    {
        $message = Message::factory()->create([
            'account_id' => $this->account->id,
            'inbox_id' => $this->inbox->id,
            'conversation_id' => $this->conversation->id,
            'sender_id' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->deleteJson("/api/v1/accounts/{$this->account->id}/conversations/{$this->conversation->id}/messages/{$message->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('messages', [
            'id' => $message->id,
        ]);
    }
}

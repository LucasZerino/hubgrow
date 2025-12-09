<?php

namespace Tests\Feature\Api\V1\Accounts;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Contact;
use App\Models\User;
use App\Support\Current;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testes de Contacts
 * 
 * @package Tests\Feature\Api\V1\Accounts
 */
class ContactsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Account $account;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->create();
        $this->user = User::factory()->create();
        
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
     * Testa listar contatos
     */
    public function test_can_list_contacts(): void
    {
        Contact::factory()->count(3)->create([
            'account_id' => $this->account->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->getJson("/api/v1/accounts/{$this->account->id}/contacts");

        $response->assertStatus(200);
        
        // Verifica estrutura de paginação
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'account_id'],
            ],
        ]);
        
        $this->assertCount(3, $response->json('data'));
    }

    /**
     * Testa buscar contatos
     */
    public function test_can_search_contacts(): void
    {
        Contact::factory()->create([
            'account_id' => $this->account->id,
            'name' => 'João Silva',
            'email' => 'joao@example.com',
        ]);

        Contact::factory()->create([
            'account_id' => $this->account->id,
            'name' => 'Maria Santos',
            'email' => 'maria@example.com',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->getJson("/api/v1/accounts/{$this->account->id}/contacts?search=João");

        $response->assertStatus(200);
        
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('João Silva', $data[0]['name']);
    }

    /**
     * Testa criar contato
     */
    public function test_can_create_contact(): void
    {
        $data = [
            'name' => 'Novo Contato',
            'email' => 'novo@example.com',
            'phone_number' => '+5511999999999',
        ];

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->postJson("/api/v1/accounts/{$this->account->id}/contacts", $data);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'id',
                     'name',
                     'email',
                     'phone_number',
                     'account_id',
                 ]);

        $this->assertDatabaseHas('contacts', [
            'name' => 'Novo Contato',
            'email' => 'novo@example.com',
            'account_id' => $this->account->id,
        ]);
    }

    /**
     * Testa mostrar contato específico
     */
    public function test_can_show_contact(): void
    {
        $contact = Contact::factory()->create([
            'account_id' => $this->account->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->getJson("/api/v1/accounts/{$this->account->id}/contacts/{$contact->id}");

        $response->assertStatus(200)
                 ->assertJson([
                     'id' => $contact->id,
                     'name' => $contact->name,
                 ]);
    }

    /**
     * Testa atualizar contato
     */
    public function test_can_update_contact(): void
    {
        $contact = Contact::factory()->create([
            'account_id' => $this->account->id,
        ]);

        $data = [
            'name' => 'Nome Atualizado',
            'email' => 'atualizado@example.com',
        ];

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->putJson("/api/v1/accounts/{$this->account->id}/contacts/{$contact->id}", $data);

        $response->assertStatus(200)
                 ->assertJson([
                     'name' => 'Nome Atualizado',
                     'email' => 'atualizado@example.com',
                 ]);
    }

    /**
     * Testa deletar contato
     */
    public function test_can_delete_contact(): void
    {
        $contact = Contact::factory()->create([
            'account_id' => $this->account->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->deleteJson("/api/v1/accounts/{$this->account->id}/contacts/{$contact->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('contacts', [
            'id' => $contact->id,
        ]);
    }
}

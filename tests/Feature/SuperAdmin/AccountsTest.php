<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testes de SuperAdmin
 * 
 * @package Tests\Feature\SuperAdmin
 */
class AccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->token = $this->superAdmin->createToken('test-token')->plainTextToken;
    }

    /**
     * Testa listar todas as accounts (super admin)
     */
    public function test_super_admin_can_list_all_accounts(): void
    {
        Account::factory()->count(3)->create();

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->getJson('/api/super-admin/accounts');

        $response->assertStatus(200);
        
        // Verifica que retorna array de accounts
        $response->assertJsonStructure([
            '*' => ['id', 'name'],
        ]);
    }

    /**
     * Testa usuário normal não pode acessar rotas de super admin
     */
    public function test_normal_user_cannot_access_super_admin_routes(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
                         ->getJson('/api/super-admin/accounts');

        $response->assertStatus(403)
                 ->assertJson(['error' => 'Acesso negado. Apenas super administradores podem acessar este recurso.']);
    }

    /**
     * Testa mostrar account específica (super admin)
     */
    public function test_super_admin_can_show_account(): void
    {
        $account = Account::factory()->create();

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->getJson("/api/super-admin/accounts/{$account->id}");

        $response->assertStatus(200)
                 ->assertJson([
                     'id' => $account->id,
                     'name' => $account->name,
                 ]);
    }

    /**
     * Testa criar account (super admin)
     */
    public function test_super_admin_can_create_account(): void
    {
        $data = [
            'name' => 'Nova Account',
            'status' => Account::STATUS_ACTIVE,
        ];

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->postJson('/api/super-admin/accounts', $data);

        $response->assertStatus(201)
                 ->assertJson([
                     'name' => 'Nova Account',
                 ]);

        $this->assertDatabaseHas('accounts', [
            'name' => 'Nova Account',
        ]);
    }

    /**
     * Testa atualizar account (super admin)
     */
    public function test_super_admin_can_update_account(): void
    {
        $account = Account::factory()->create();

        $data = [
            'name' => 'Nome Atualizado',
            'status' => Account::STATUS_SUSPENDED,
        ];

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->putJson("/api/super-admin/accounts/{$account->id}", $data);

        $response->assertStatus(200)
                 ->assertJson([
                     'name' => 'Nome Atualizado',
                     'status' => Account::STATUS_SUSPENDED,
                 ]);
    }

    /**
     * Testa deletar account (super admin)
     */
    public function test_super_admin_can_delete_account(): void
    {
        $account = Account::factory()->create();

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->deleteJson("/api/super-admin/accounts/{$account->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('accounts', [
            'id' => $account->id,
        ]);
    }
}

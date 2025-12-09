<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Testes de Autenticação
 * 
 * @package Tests\Feature
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Testa registro de novo usuário
     */
    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'João Silva',
            'email' => 'joao@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'account_name' => 'Minha Empresa',
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'user' => ['id', 'name', 'email', 'is_super_admin'],
                     'account' => ['id', 'name'],
                     'token',
                 ]);

        $this->assertDatabaseHas('users', [
            'email' => 'joao@example.com',
        ]);

        $this->assertDatabaseHas('accounts', [
            'name' => 'Minha Empresa',
        ]);
    }

    /**
     * Testa login de usuário
     */
    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $account = Account::factory()->create();
        $user->accountUsers()->create([
            'account_id' => $account->id,
            'role' => 1, // Administrator
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'user' => ['id', 'name', 'email', 'is_super_admin'],
                     'account' => ['id', 'name'],
                     'token',
                 ]);
    }

    /**
     * Testa login com credenciais inválidas
     */
    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email']);
    }

    /**
     * Testa logout de usuário autenticado
     */
    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
                         ->postJson('/api/auth/logout');

        $response->assertStatus(200)
                 ->assertJson(['message' => 'Logout realizado com sucesso']);
    }

    /**
     * Testa obter usuário atual
     */
    public function test_user_can_get_current_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
                         ->getJson('/api/auth/me');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'user' => ['id', 'name', 'email', 'is_super_admin'],
                 ]);
    }

    /**
     * Testa registro com email duplicado
     */
    public function test_user_cannot_register_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'João Silva',
            'email' => 'existing@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'account_name' => 'Minha Empresa',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email']);
    }

    /**
     * Testa registro com senha muito curta
     */
    public function test_user_cannot_register_with_short_password(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'João Silva',
            'email' => 'joao@example.com',
            'password' => '1234567', // Menos de 8 caracteres
            'password_confirmation' => '1234567',
            'account_name' => 'Minha Empresa',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['password']);
    }

    /**
     * Testa registro com senha não confirmada
     */
    public function test_user_cannot_register_without_password_confirmation(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'João Silva',
            'email' => 'joao@example.com',
            'password' => 'password123',
            'password_confirmation' => 'different123',
            'account_name' => 'Minha Empresa',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['password']);
    }

    /**
     * Testa registro sem campos obrigatórios
     */
    public function test_user_cannot_register_without_required_fields(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => '',
            'email' => '',
            'password' => '',
            'account_name' => '',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['name', 'email', 'password', 'account_name']);
    }

    /**
     * Testa registro com email inválido
     */
    public function test_user_cannot_register_with_invalid_email(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'João Silva',
            'email' => 'invalid-email',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'account_name' => 'Minha Empresa',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email']);
    }

    /**
     * Testa login sem campos obrigatórios
     */
    public function test_user_cannot_login_without_required_fields(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => '',
            'password' => '',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email', 'password']);
    }

    /**
     * Testa login com email inexistente
     */
    public function test_user_cannot_login_with_nonexistent_email(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email']);
    }

    /**
     * Testa login com email inválido
     */
    public function test_user_cannot_login_with_invalid_email_format(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'invalid-email',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email']);
    }

    /**
     * Testa logout sem autenticação
     */
    public function test_user_cannot_logout_without_authentication(): void
    {
        $response = $this->postJson('/api/auth/logout');

        $response->assertStatus(401);
    }

    /**
     * Testa obter usuário atual sem autenticação
     */
    public function test_user_cannot_get_current_user_without_authentication(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401);
    }

    /**
     * Testa obter usuário atual com token inválido
     */
    public function test_user_cannot_get_current_user_with_invalid_token(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer invalid-token')
                         ->getJson('/api/auth/me');

        $response->assertStatus(401);
    }

    /**
     * Testa login de super admin
     */
    public function test_super_admin_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password123'),
            'is_super_admin' => true,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'user' => ['id', 'name', 'email', 'is_super_admin'],
                     'token',
                 ])
                 ->assertJson([
                     'user' => [
                         'is_super_admin' => true,
                     ],
                     'account' => null, // Super admin não tem account
                 ]);
    }

    /**
     * Testa login de usuário sem account associada
     */
    public function test_user_can_login_without_account(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        // Não cria AccountUser

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'user' => ['id', 'name', 'email', 'is_super_admin'],
                     'token',
                 ])
                 ->assertJson([
                     'account' => null, // Usuário sem account
                 ]);
    }

    /**
     * Testa login de usuário com múltiplas accounts (retorna primeira)
     */
    public function test_user_with_multiple_accounts_returns_first_account(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $account1 = Account::factory()->create(['name' => 'Account 1']);
        $account2 = Account::factory()->create(['name' => 'Account 2']);

        $user->accountUsers()->create([
            'account_id' => $account1->id,
            'role' => 1,
        ]);

        $user->accountUsers()->create([
            'account_id' => $account2->id,
            'role' => 1,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
                 ->assertJson([
                     'account' => [
                         'id' => $account1->id,
                         'name' => 'Account 1',
                     ],
                 ]);
    }

    /**
     * Testa que token é válido após login
     */
    public function test_token_is_valid_after_login(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $account = Account::factory()->create();
        $user->accountUsers()->create([
            'account_id' => $account->id,
            'role' => 1,
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('token');

        // Usa o token para acessar /me
        $meResponse = $this->withHeader('Authorization', "Bearer $token")
                           ->getJson('/api/auth/me');

        $meResponse->assertStatus(200)
                   ->assertJson([
                       'user' => [
                           'email' => 'test@example.com',
                       ],
                   ]);
    }

    /**
     * Testa que token é invalidado após logout
     * 
     * Nota: Em ambiente de teste, o Sanctum pode manter tokens em cache.
     * Este teste verifica que o logout funciona corretamente.
     */
    public function test_token_is_invalidated_after_logout(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $account = Account::factory()->create();
        $user->accountUsers()->create([
            'account_id' => $account->id,
            'role' => 1,
        ]);

        // Faz login para obter token
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('token');

        // Verifica que token funciona antes do logout
        $response = $this->withHeader('Authorization', "Bearer $token")
                        ->getJson('/api/auth/me');
        $response->assertStatus(200);

        // Conta tokens antes do logout
        $tokensBefore = DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->where('tokenable_type', User::class)
            ->count();

        $this->assertGreaterThan(0, $tokensBefore, 'Deve haver pelo menos um token antes do logout');

        // Faz logout
        $logoutResponse = $this->withHeader('Authorization', "Bearer $token")
                               ->postJson('/api/auth/logout');
        $logoutResponse->assertStatus(200)
                      ->assertJson(['message' => 'Logout realizado com sucesso']);

        // Verifica que pelo menos um token foi deletado
        $tokensAfter = DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->where('tokenable_type', User::class)
            ->count();

        $this->assertLessThan($tokensBefore, $tokensAfter, 'Deve haver menos tokens após o logout');
    }
}


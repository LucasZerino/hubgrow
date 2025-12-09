<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Inbox;
use App\Models\User;
use App\Support\Current;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testes de Limites de Account
 * 
 * @package Tests\Feature
 */
class AccountLimitsTest extends TestCase
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
     * Testa que super admin não tem limites
     */
    public function test_super_admin_has_no_limits(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $superAdminToken = $superAdmin->createToken('test-token')->plainTextToken;

        // Define limite de 1 inbox
        $this->account->setLimit('inboxes', 1);

        // Cria 1 inbox
        Inbox::factory()->create(['account_id' => $this->account->id]);

        // Super admin pode criar mesmo com limite
        Current::setUser($superAdmin);

        $data = [
            'name' => 'Segundo Inbox',
            'channel_type' => 'App\\Models\\Channel\\WebWidgetChannel',
            'channel_id' => 2,
        ];

        $response = $this->withHeader('Authorization', "Bearer $superAdminToken")
                         ->postJson("/api/v1/accounts/{$this->account->id}/inboxes", $data);

        // Super admin deve conseguir criar (não tem limites)
        $response->assertStatus(201);
    }

    /**
     * Testa limite de inboxes
     */
    public function test_inbox_limit_enforcement(): void
    {
        // Define limite de 2 inboxes
        $this->account->setLimit('inboxes', 2);

        // Cria 2 inboxes
        Inbox::factory()->count(2)->create(['account_id' => $this->account->id]);

        // Tenta criar terceiro (deve falhar)
        $data = [
            'name' => 'Terceiro Inbox',
            'channel_type' => 'App\\Models\\Channel\\WebWidgetChannel',
            'channel_id' => 3,
        ];

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->postJson("/api/v1/accounts/{$this->account->id}/inboxes", $data);

        $response->assertStatus(402)
                 ->assertJsonStructure(['error', 'message', 'usage'])
                 ->assertJson([
                     'error' => 'Limite de inboxes excedido',
                 ]);
    }

    /**
     * Testa verificação de uso de recursos
     */
    public function test_can_get_resource_usage(): void
    {
        // Define limite de 5 inboxes
        $this->account->setLimit('inboxes', 5);

        // Cria 2 inboxes
        Inbox::factory()->count(2)->create(['account_id' => $this->account->id]);

        $usage = $this->account->getResourceUsage('inboxes');

        $this->assertEquals(2, $usage['current']);
        $this->assertEquals(5, $usage['limit']);
        $this->assertEquals(3, $usage['available']);
    }

    /**
     * Testa limite ilimitado
     */
    public function test_unlimited_resources(): void
    {
        // Não define limite (ilimitado por padrão)
        $this->account->setLimit('inboxes', PHP_INT_MAX);

        // Deve conseguir criar quantos quiser
        Inbox::factory()->count(10)->create(['account_id' => $this->account->id]);

        $data = [
            'name' => 'Mais um Inbox',
            'channel_type' => 'App\\Models\\Channel\\WebWidgetChannel',
            'channel_id' => 11,
        ];

        $response = $this->withHeader('Authorization', "Bearer $this->token")
                         ->postJson("/api/v1/accounts/{$this->account->id}/inboxes", $data);

        $response->assertStatus(201);
    }

    /**
     * Testa atualização de limites via super admin
     */
    public function test_super_admin_can_update_limits(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $superAdminToken = $superAdmin->createToken('test-token')->plainTextToken;

        $account = Account::factory()->create();

        $data = [
            'limits' => [
                'inboxes' => 10,
                'agents' => 5,
                'whatsapp_channels' => 3,
            ],
        ];

        $response = $this->withHeader('Authorization', "Bearer $superAdminToken")
                         ->putJson("/api/super-admin/accounts/{$account->id}/limits", $data);

        $response->assertStatus(200)
                 ->assertJsonStructure(['account', 'limits']);

        $account->refresh();
        $this->assertEquals(10, $account->getLimit('inboxes'));
        $this->assertEquals(5, $account->getLimit('agents'));
        $this->assertEquals(3, $account->getLimit('whatsapp_channels'));
    }
}

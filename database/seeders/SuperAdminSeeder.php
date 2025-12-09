<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder SuperAdminSeeder
 * 
 * Cria um super admin padrão para o sistema.
 * 
 * @package Database\Seeders
 */
class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Cria super admin
        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@hubphp.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('admin123'),
                'is_super_admin' => true,
            ]
        );

        $this->command->info("Super Admin criado: {$superAdmin->email} / admin123");

        // Cria uma account de teste para o super admin
            $account = Account::firstOrCreate(
                ['name' => 'Account de Teste'],
                [
                    'status' => Account::STATUS_ACTIVE,
                    'locale' => Account::LOCALE_PT_BR,
                ]
            );

            // Vincula super admin à account como administrador
            AccountUser::firstOrCreate(
                [
                    'account_id' => $account->id,
                    'user_id' => $superAdmin->id,
                ],
                [
                    'role' => AccountUser::ROLE_ADMINISTRATOR,
                ]
            );

        $this->command->info("Account de teste criada: {$account->name} (ID: {$account->id})");
    }
}

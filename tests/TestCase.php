<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Setup do teste
     * 
     * Executa migrations antes de cada teste
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Executa migrations para cada teste
        // Comentado por padrão - descomente se quiser resetar banco a cada teste
        // $this->artisan('migrate:fresh')->run();
    }

    /**
     * Tear down do teste
     * 
     * Limpa dados após cada teste
     */
    protected function tearDown(): void
    {
        // Limpa cache e sessões
        $this->artisan('cache:clear')->run();
        $this->artisan('config:clear')->run();

        parent::tearDown();
    }
}

<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Com o cache de configuração ativo (php artisan config:cache /
        // optimize / composer otimizar), o Laravel IGNORA as variáveis do
        // phpunit.xml e os testes passariam a usar o .env do desenvolvedor:
        // banco real, S3 real, credenciais reais. Melhor parar aqui.
        if ($this->app->configurationIsCached()) {
            $this->fail(
                'Cache de configuração ativo (bootstrap/cache/config.php): os testes usariam o .env real. '
                .'Rode "php artisan config:clear" (ou "composer test") e execute de novo.'
            );
        }
    }
}

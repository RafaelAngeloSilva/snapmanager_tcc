<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Configuração de produção (deploy na Railway): CORS por variável, HTTPS e
 * IP do cliente atrás do proxy, DB_URL do Postgres e health check.
 *
 * Nada aqui conecta em serviço externo: o Postgres é só configurado (a
 * conexão PDO do Laravel é preguiçosa) e as requisições são simuladas.
 */
class ProductionDeployConfigTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            $this->setEnv($key, $value === false ? null : $value);
        }

        parent::tearDown();
    }

    private function setEnv(string $key, ?string $value): void
    {
        if (! array_key_exists($key, $this->originalEnv)) {
            $this->originalEnv[$key] = $_ENV[$key] ?? $_SERVER[$key] ?? false;
        }

        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    /** Lê config/cors.php como o Laravel leria com estas variáveis de ambiente. */
    private function corsConfigWith(array $env): array
    {
        foreach (['APP_ENV', 'FRONTEND_URL', 'CORS_ALLOWED_ORIGINS'] as $key) {
            $this->setEnv($key, $env[$key] ?? null);
        }

        return require config_path('cors.php');
    }

    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/login', server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);
    }

    // ------------------------------------------------------------------ CORS

    public function test_cors_in_production_allows_only_the_frontend_url(): void
    {
        $cors = $this->corsConfigWith(['APP_ENV' => 'production', 'FRONTEND_URL' => 'https://snapmanager.vercel.app/']);

        $this->assertSame(['https://snapmanager.vercel.app'], $cors['allowed_origins']);
        $this->assertSame([], $cors['allowed_origins_patterns']);
        $this->assertFalse($cors['supports_credentials']);
        $this->assertNotContains('*', $cors['allowed_origins']);

        config(['cors' => $cors]);

        $this->preflight('https://snapmanager.vercel.app')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://snapmanager.vercel.app');

        // Com uma única origem configurada, o php-cors sempre responde com
        // ELA; o navegador bloqueia porque não bate com a origem de quem
        // chamou. O que importa: nunca ecoar uma origem não autorizada.
        foreach (['https://site-malicioso.example', 'http://localhost:5173', 'https://snapmanager.vercel.app.evil.com'] as $origin) {
            $this->assertNotSame(
                $origin,
                $this->preflight($origin)->headers->get('Access-Control-Allow-Origin'),
                "Origem {$origin} não deveria ser aceita em produção.",
            );
        }
    }

    public function test_cors_allowed_origins_variable_accepts_a_list(): void
    {
        $cors = $this->corsConfigWith([
            'APP_ENV' => 'production',
            'FRONTEND_URL' => 'https://ignorado.example',
            'CORS_ALLOWED_ORIGINS' => ' https://snapmanager.vercel.app , https://www.estudio.com.br/ ,',
        ]);

        $this->assertSame(['https://snapmanager.vercel.app', 'https://www.estudio.com.br'], $cors['allowed_origins']);
    }

    public function test_cors_in_local_development_keeps_localhost_and_lan_on_any_port(): void
    {
        $cors = $this->corsConfigWith(['APP_ENV' => 'local']);

        $this->assertSame(['http://localhost:5173'], $cors['allowed_origins']);

        config(['cors' => $cors]);

        foreach (['http://localhost:5173', 'http://localhost:5174', 'http://127.0.0.1:5173', 'http://192.168.0.15:5173', 'http://10.0.0.8:4173'] as $origin) {
            $this->preflight($origin)->assertHeader('Access-Control-Allow-Origin', $origin);
        }

        foreach (['http://localhost.evil.com', 'https://192.168.0.15.evil.com', 'https://site-malicioso.example'] as $origin) {
            $this->assertNotSame($origin, $this->preflight($origin)->headers->get('Access-Control-Allow-Origin'), $origin);
        }
    }

    // ------------------------------------------------- proxy HTTPS (Railway)

    private function registerEchoRoute(): void
    {
        Route::get('/_teste/proxy', fn (Request $request) => [
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'url' => url('/api/public/galleries/abc/images/1/thumbnail'),
        ]);
    }

    public function test_behind_the_railway_proxy_request_is_https_and_ip_is_the_client(): void
    {
        $this->registerEchoRoute();

        // O proxy fala HTTP com o container; o Host é o domínio público.
        $response = $this->call('GET', 'http://snapmanager-production.up.railway.app/_teste/proxy', server: [
            'REMOTE_ADDR' => '100.64.0.2',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            // O cliente tentou forjar o próprio IP; o proxy da Railway
            // acrescenta o IP real à direita.
            'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 200.150.10.20',
            'HTTP_X_FORWARDED_HOST' => 'site-malicioso.example',
            'HTTP_X_FORWARDED_PORT' => '8443',
        ]);

        $response->assertOk()->assertExactJson([
            'ip' => '200.150.10.20',
            'secure' => true,
            // Host e porta encaminhados NÃO são confiáveis.
            'url' => 'https://snapmanager-production.up.railway.app/api/public/galleries/abc/images/1/thumbnail',
        ]);
    }

    public function test_without_proxy_local_development_is_unchanged(): void
    {
        $this->registerEchoRoute();

        $this->call('GET', 'http://localhost:8000/_teste/proxy', server: ['REMOTE_ADDR' => '127.0.0.1'])
            ->assertOk()
            ->assertExactJson([
                'ip' => '127.0.0.1',
                'secure' => false,
                'url' => 'http://localhost:8000/api/public/galleries/abc/images/1/thumbnail',
            ]);
    }

    public function test_https_app_url_forces_https_urls_even_without_forwarded_proto(): void
    {
        config(['app.url' => 'https://snapmanager-production.up.railway.app']);
        (new AppServiceProvider($this->app))->boot();
        $this->registerEchoRoute();

        try {
            $this->call('GET', 'http://snapmanager-production.up.railway.app/_teste/proxy', server: ['REMOTE_ADDR' => '100.64.0.2'])
                ->assertOk()
                ->assertJsonPath('url', 'https://snapmanager-production.up.railway.app/api/public/galleries/abc/images/1/thumbnail');
        } finally {
            URL::forceScheme(null);
        }
    }

    // ------------------------------------------------------ banco e health

    public function test_postgres_connection_accepts_railway_database_url(): void
    {
        config([
            'database.connections.pgsql.url' => 'postgresql://usuario:senha-falsa@postgres.railway.internal:5432/railway',
        ]);

        $connection = DB::connection('pgsql');

        $this->assertSame('pgsql', $connection->getDriverName());
        $this->assertSame('postgres.railway.internal', $connection->getConfig('host'));
        $this->assertSame(5432, (int) $connection->getConfig('port'));
        $this->assertSame('railway', $connection->getConfig('database'));
        $this->assertSame('usuario', $connection->getConfig('username'));

        DB::purge('pgsql');
    }

    public function test_health_check_is_public_and_does_not_touch_the_database(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->get('/up')->assertOk();

        $this->assertSame(0, $queries);
    }
}

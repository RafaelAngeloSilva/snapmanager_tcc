<?php

/*
|--------------------------------------------------------------------------
| CORS — quem pode chamar a API pelo navegador
|--------------------------------------------------------------------------
|
| O front-end (React) roda em outra origem (Vite local; Vercel em produção)
| e se autentica por token Bearer no cabeçalho Authorization — sem cookies.
| Por isso supports_credentials fica false e as origens são uma lista
| EXPLÍCITA, nunca "*":
|
|  - CORS_ALLOWED_ORIGINS: origens separadas por vírgula
|    (ex.: https://snapmanager.vercel.app,https://www.meudominio.com.br).
|    Vazio = usa FRONTEND_URL (a mesma URL dos links de e-mail e do login
|    com Google), então em produção basta configurar FRONTEND_URL.
|  - Com APP_ENV=local, também vale qualquer porta de localhost/127.0.0.1 e
|    da rede local (192.168.x.x, 10.x.x.x, 172.16-31.x.x) — para testar o
|    front pelo celular, por exemplo. Em produção isso NÃO se aplica.
|
*/

$origins = (string) env('CORS_ALLOWED_ORIGINS', '');

if (trim($origins) === '') {
    $origins = (string) env('FRONTEND_URL', 'http://localhost:5173');
}

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // O navegador envia a origem sem barra final; normaliza a configuração.
    'allowed_origins' => array_values(array_filter(array_map(
        fn (string $origin) => rtrim(trim($origin), '/'),
        explode(',', $origins),
    ))),

    'allowed_origins_patterns' => env('APP_ENV') === 'local'
        ? ['#^https?://(localhost|127\.0\.0\.1|\[::1\]|192\.168\.\d{1,3}\.\d{1,3}|10\.\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3})(:\d+)?$#']
        : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Autenticação por token Bearer (sem cookies): nunca true com origens abertas.
    'supports_credentials' => false,

];

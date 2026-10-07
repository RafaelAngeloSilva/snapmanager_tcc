<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Produção (Railway): o HTTPS termina no proxy da plataforma e a
        // requisição chega aqui em HTTP. Confia só no proxy que chamou
        // (REMOTE_ADDR — '*' no Laravel não é "qualquer IP") e só nos
        // cabeçalhos de IP do cliente e de protocolo: $request->ip() passa a
        // ser o do cliente (throttles por IP) e isSecure() fica correto.
        // Host/porta encaminhados NÃO são confiáveis. Em desenvolvimento,
        // sem proxy, nada muda.
        $middleware->trustProxies(
            at: '*',
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Armazenamento de arquivos (local ou S3): mensagem amigável, sem
        // object key nem detalhe técnico — esses vão para o log, em
        // App\Services\FileStorage.
        $exceptions->render(fn (\App\Exceptions\StorageUnavailableException $e) => response()->json(
            ['message' => \App\Exceptions\StorageUnavailableException::USER_MESSAGE],
            503,
        ));

        $exceptions->render(fn (\App\Exceptions\StoredFileNotFoundException $e) => response()->json(
            ['message' => \App\Exceptions\StoredFileNotFoundException::USER_MESSAGE],
            404,
        ));

        // Já registradas no log por FileStorage, com a mensagem sanitizada.
        $exceptions->dontReport([
            \App\Exceptions\StorageUnavailableException::class,
            \App\Exceptions\StoredFileNotFoundException::class,
        ]);
    })->create();

<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // No '/api' prefix (Laravel's own default) — routes/api.php's own
        // paths (/0/public/..., /0/private/...) must match real Kraken's
        // path shape exactly, not be nested under one more segment.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'kraken.signed' => \App\Http\Middleware\VerifyKrakenSignature::class,
            'bearer.auth' => \App\Http\Middleware\VerifyBearerToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

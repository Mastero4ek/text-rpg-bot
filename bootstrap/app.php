<?php

declare(strict_types=1);

use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\VerifyTelegramIp;
use App\Http\Middleware\VerifyTelegramWebhookSecret;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::post('/telegram/webhook', TelegramWebhookController::class)
                ->middleware([
                    VerifyTelegramWebhookSecret::class,
                    VerifyTelegramIp::class,
                ]);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

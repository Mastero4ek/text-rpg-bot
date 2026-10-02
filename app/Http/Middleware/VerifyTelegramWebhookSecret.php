<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyTelegramWebhookSecret
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('bot.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            abort(403, 'Webhook secret is not configured.');
        }

        $header = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (! is_string($header) || ! hash_equals($secret, $header)) {
            abort(403, 'Invalid Telegram webhook secret.');
        }

        return $next($request);
    }
}

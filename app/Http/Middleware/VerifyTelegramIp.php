<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

final class VerifyTelegramIp
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment(['local', 'testing'])) {
            return $next($request);
        }

        $allowed = config('bot.allowed_ips');

        if (! is_array($allowed) || $allowed === []) {
            abort(403, 'Telegram IP allowlist is empty.');
        }

        $ranges = [];

        foreach ($allowed as $range) {
            if (is_string($range)) {
                $ranges[] = $range;
            }
        }

        if (! IpUtils::checkIp($request->ip() ?? '', $ranges)) {
            abort(403, 'Request IP is not from Telegram.');
        }

        return $next($request);
    }
}

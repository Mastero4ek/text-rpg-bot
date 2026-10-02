<?php

declare(strict_types=1);

return [
    'token' => env('TELEGRAM_BOT_TOKEN', ''),
    'webhook_url' => env('TELEGRAM_WEBHOOK_URL', ''),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET', ''),
    'async' => (bool) env('TELEGRAM_ASYNC', false),
    'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
    'poll_timeout' => 25,
    /*
     * Official Telegram Bot API IP ranges (IPv4).
     * @see https://core.telegram.org/bots/webhooks#the-short-version
     */
    'allowed_ips' => [
        '149.154.160.0/20',
        '91.108.4.0/22',
    ],
];

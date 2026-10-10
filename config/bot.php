<?php

declare(strict_types=1);

return [
    'token' => env('TELEGRAM_BOT_TOKEN', ''),
    'webhook_url' => env('TELEGRAM_WEBHOOK_URL', ''),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET', ''),
    'async' => (bool) env('TELEGRAM_ASYNC', false),
    'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
    'poll_timeout' => 25,
    'registration_rest_image' => resource_path('images/telegram/location/registration_rest.png'),
    'registration_up_image' => resource_path('images/telegram/location/registration_up.png'),
    'overseer_image' => resource_path('images/telegram/npc/overseer.png'),
    'overseer_tavern_image' => resource_path('images/telegram/npc/overseer_tavern.png'),
    'healer_tavern_image' => resource_path('images/telegram/npc/healer_tavern.png'),
    'blacksmith_tavern_image' => resource_path('images/telegram/npc/blacksmith_tavern.png'),
    'buyer_tavern_image' => resource_path('images/telegram/npc/buyer_tavern.png'),
    'city_gates_image' => resource_path('images/telegram/location/gates.png'),
    'city_forest_image' => resource_path('images/telegram/location/forest.png'),
    'city_portal_image' => resource_path('images/telegram/location/portal_city.png'),
    'idle_session_image' => resource_path('images/telegram/location/portal_session.png'),
    'city_quest_board_image' => resource_path('images/telegram/location/quest_board.png'),
    'city_tavern_image' => resource_path('images/telegram/location/tavern.png'),
    'city_arena_image' => resource_path('images/telegram/location/arena.png'),
    /*
     * Official Telegram Bot API IP ranges (IPv4).
     * @see https://core.telegram.org/bots/webhooks#the-short-version
     */
    'allowed_ips' => [
        '149.154.160.0/20',
        '91.108.4.0/22',
    ],
];

<?php

declare(strict_types=1);

return [
    'token' => env('TELEGRAM_BOT_TOKEN', ''),
    'webhook_url' => env('TELEGRAM_WEBHOOK_URL', ''),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET', ''),
    'async' => (bool) env('TELEGRAM_ASYNC', false),
    'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
    'demo' => [
        'enabled' => (bool) env('DEMO_MODE', false),
        'max_level' => (int) env('DEMO_MAX_LEVEL', 3),
    ],
    'poll_timeout' => 25,
    'registration_rest_image' => resource_path('images/telegram/location/registration_rest.png'),
    'registration_up_image' => resource_path('images/telegram/location/registration_up.png'),
    'character_skin_images' => [
        'mobile' => resource_path('images/telegram/character/mobile.png'),
        'heavy' => resource_path('images/telegram/character/heavy.png'),
        'ward' => resource_path('images/telegram/character/ward.png'),
    ],
    'overseer_image' => resource_path('images/telegram/npc/overseer.png'),
    'overseer_tavern_image' => resource_path('images/telegram/npc/overseer_tavern.png'),
    'healer_image' => resource_path('images/telegram/npc/healer.png'),
    'healer_tavern_image' => resource_path('images/telegram/npc/healer_tavern.png'),
    'blacksmith_image' => resource_path('images/telegram/npc/blacksmith.png'),
    'blacksmith_tavern_image' => resource_path('images/telegram/npc/blacksmith_tavern.png'),
    'buyer_image' => resource_path('images/telegram/npc/buyer.png'),
    'buyer_tavern_image' => resource_path('images/telegram/npc/buyer_tavern.png'),
    'bruiser_image' => resource_path('images/telegram/npc/bruizer.png'),
    'bruiser_fight_image' => resource_path('images/telegram/npc/bruizer_fight.png'),
    'bruiser_port_image' => resource_path('images/telegram/npc/bruizer_port.png'),
    'barkeeper_tavern_image' => resource_path('images/telegram/npc/barkeeper_tavern.png'),
    'lucky_barrels_image' => resource_path('images/telegram/location/lucky_barrels.png'),
    'city_gates_image' => resource_path('images/telegram/location/gates.png'),
    'city_forest_image' => resource_path('images/telegram/location/forest.png'),
    'city_swamp_image' => resource_path('images/telegram/location/swamp.png'),
    'city_ruins_image' => resource_path('images/telegram/location/ruins.png'),
    'city_port_image' => resource_path('images/telegram/location/port.png'),
    'city_portal_image' => resource_path('images/telegram/location/portal_city.png'),
    'idle_session_image' => resource_path('images/telegram/location/portal_city.png'),
    'jail_image' => resource_path('images/telegram/location/jail.png'),
    'city_quest_board_image' => resource_path('images/telegram/location/quest_board.png'),
    'city_tavern_image' => resource_path('images/telegram/location/tavern.png'),
    'city_lists_image' => resource_path('images/telegram/location/arena_outer.png'),
    'city_arena_image' => resource_path('images/telegram/location/arena.png'),
    'library_image' => resource_path('images/telegram/menu/library-closed.png'),
    'library_opened_image' => resource_path('images/telegram/menu/library-opened.png'),
    'bag_image' => resource_path('images/telegram/menu/bag.png'),
    'backpack_image' => resource_path('images/telegram/menu/backpack.png'),
    'journal_image' => resource_path('images/telegram/menu/contracts.png'),
    'appeal_image' => resource_path('images/telegram/menu/appeal-letter.png'),
    /*
     * Official Telegram Bot API IP ranges (IPv4).
     * @see https://core.telegram.org/bots/webhooks#the-short-version
     */
    'allowed_ips' => [
        '149.154.160.0/20',
        '91.108.4.0/22',
    ],
];

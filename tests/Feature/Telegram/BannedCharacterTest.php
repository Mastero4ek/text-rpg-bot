<?php

declare(strict_types=1);

use App\Models\Character\Character;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('replies archived and stops processing for soft deleted character', function (): void {
    $p = characters()->createDraft(1301);
    $p->username = 'ArchivedHero';
    $p->save();
    $p->delete();

    $payload = [
        'update_id' => 99,
        'message' => [
            'message_id' => 1,
            'text' => '/start',
            'from' => ['id' => 1301, 'is_bot' => false, 'first_name' => 'B'],
            'chat' => ['id' => 1301, 'type' => 'private'],
        ],
    ];

    $this->postJson('/telegram/webhook', $payload, [
        'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
    ])->assertOk();

    expect(Character::withTrashed()->findOrFail(1301)->trashed())->toBeTrue()
        ->and(Character::withTrashed()->findOrFail(1301)->username)->toBe('ArchivedHero');

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/sendMessage')) {
            return false;
        }

        return ($request['text'] ?? null) === __('errors.archived');
    });
});

it('replies archived on callback for soft deleted character', function (): void {
    $p = characters()->createDraft(1302);
    $p->delete();

    $payload = [
        'update_id' => 100,
        'callback_query' => [
            'id' => 'cb1',
            'from' => ['id' => 1302, 'is_bot' => false, 'first_name' => 'B'],
            'message' => [
                'message_id' => 2,
                'chat' => ['id' => 1302, 'type' => 'private'],
            ],
            'data' => 'menu:home',
        ],
    ];

    $this->postJson('/telegram/webhook', $payload, [
        'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
    ])->assertOk();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === __('errors.archived');
    });
});

it('replies banned while banned_until is in the future', function (): void {
    $p = characters()->createDraft(1303);
    $p->banned_until = now()->addDay();
    $p->save();

    $until = $p->fresh()->banned_until->timezone(config('app.timezone'))->format('d.m.Y H:i') . ' (МСК)';

    $payload = [
        'update_id' => 101,
        'message' => [
            'message_id' => 3,
            'text' => '/start',
            'from' => ['id' => 1303, 'is_bot' => false, 'first_name' => 'B'],
            'chat' => ['id' => 1303, 'type' => 'private'],
        ],
    ];

    $this->postJson('/telegram/webhook', $payload, [
        'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
    ])->assertOk();

    Http::assertSent(function (Request $request) use ($until): bool {
        return str_contains($request->url(), '/sendPhoto')
            && str_contains($request->body(), $until);
    });
});

it('blocks banned callback and answers callback query', function (): void {
    $p = characters()->createDraft(1305);
    $p->banned_until = now()->addDay();
    $p->save();

    $until = $p->fresh()->banned_until->timezone(config('app.timezone'))->format('d.m.Y H:i') . ' (МСК)';

    $payload = [
        'update_id' => 103,
        'callback_query' => [
            'id' => 'cb-ban',
            'from' => ['id' => 1305, 'is_bot' => false, 'first_name' => 'B'],
            'message' => [
                'message_id' => 5,
                'chat' => ['id' => 1305, 'type' => 'private'],
            ],
            'data' => 'city:home',
        ],
    ];

    $this->postJson('/telegram/webhook', $payload, [
        'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
    ])->assertOk();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/answerCallbackQuery');
    });

    Http::assertSent(function (Request $request) use ($until): bool {
        return str_contains($request->url(), '/sendPhoto')
            && str_contains($request->body(), $until);
    });
});

it('releases past ban and shows jail released panel', function (): void {
    $p = characters()->createDraft(1304);
    $p->progress_step = App\Enums\ProgressStepEnum::DONE;
    $p->username = 'Unbanned';
    $p->banned_until = now()->subMinute();
    $p->tg_chat_id = 1304;
    $p->tg_message_id = 40;
    $p->save();
    $p = placeInCity($p, App\Models\City::KEY_ANKRAT);

    $payload = [
        'update_id' => 102,
        'message' => [
            'message_id' => 4,
            'text' => '/start',
            'from' => ['id' => 1304, 'is_bot' => false, 'first_name' => 'B'],
            'chat' => ['id' => 1304, 'type' => 'private'],
        ],
    ];

    $this->postJson('/telegram/webhook', $payload, [
        'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
    ])->assertOk();

    expect($p->fresh()->banned_until)->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendPhoto')
            && str_contains($request->body(), 'city:home');
    });
});

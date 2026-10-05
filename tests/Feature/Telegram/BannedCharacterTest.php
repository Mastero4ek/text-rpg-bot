<?php

declare(strict_types=1);

use App\Models\Character;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('replies banned and stops processing for soft deleted character', function (): void {
    $p = characters()->createDraft(1301);
    $p->username = 'BannedHero';
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
        ->and(Character::withTrashed()->findOrFail(1301)->username)->toBe('BannedHero');

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/sendMessage')) {
            return false;
        }

        return ($request['text'] ?? null) === __('errors.banned');
    });
});

it('replies banned on callback for soft deleted character', function (): void {
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
            && ($request['text'] ?? null) === __('errors.banned');
    });
});

<?php

declare(strict_types=1);

use App\Enums\OnboardingStepEnum;
use App\Models\Character;
use App\Support\Telegram\TelegramClient;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
    ]);
});

it('rejects webhook without secret', function (): void {
    $this->postJson('/telegram/webhook', ['update_id' => 1])
        ->assertForbidden();
});

it('rejects webhook with wrong secret', function (): void {
    $this->postJson('/telegram/webhook', ['update_id' => 1], [
        'X-Telegram-Bot-Api-Secret-Token' => 'wrong',
    ])->assertForbidden();
});

it('processes /start and creates character', function (): void {
    $payload = [
        'update_id' => 10,
        'message' => [
            'message_id' => 1,
            'text' => '/start',
            'from' => ['id' => 4242, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => 4242, 'type' => 'private'],
        ],
    ];

    $this->postJson('/telegram/webhook', $payload, [
        'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
    ])->assertOk();

    $character = Character::query()->find(4242);
    expect($character)->not->toBeNull()
        ->and($character->onboarding_step)->toBe(OnboardingStepEnum::NICK);

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), '/sendMessage');
    });
});

it('set nick via text update', function (): void {
    onboarding()->ensurePlayer(4243);

    $payload = [
        'update_id' => 11,
        'message' => [
            'message_id' => 2,
            'text' => 'HeroNick',
            'from' => ['id' => 4243, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => 4243, 'type' => 'private'],
        ],
    ];

    $this->postJson('/telegram/webhook', $payload, [
        'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
    ])->assertOk();

    $character = Character::query()->find(4243);
    expect($character->username)->toBe('HeroNick')
        ->and($character->onboarding_step)->toBe(OnboardingStepEnum::CITY);
});

it('telegram:poll refuses when webhook url set', function (): void {
    config(['bot.webhook_url' => 'https://example.com/telegram/webhook']);

    $this->artisan('telegram:poll')
        ->assertFailed();
});

it('TelegramClient sendMessage hits api base', function (): void {
    app(TelegramClient::class)->sendMessage(1, 'hi', null);

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'api.telegram.org/bottest-token/sendMessage');
    });
});

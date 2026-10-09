<?php

declare(strict_types=1);

use App\Enums\Combat\StanceEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\ProgressStepEnum;
use App\Models\Character;
use App\Support\Telegram\TelegramClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
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
        ->and($character->progress_step)->toBe(ProgressStepEnum::SPLASH)
        ->and($character->tg_message_id)->toBe(1);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/deleteMessage')
            && (int) $request['message_id'] === 1;
    });

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendPhoto')
            && str_contains($request->body(), 'parse_mode')
            && str_contains($request->body(), mb_trim(__('telegram.registration.splash')));
    });
});

it('set nick via text update', function (): void {
    $player = registration()->ensurePlayer(4243);
    $player = registration()->rise($player);
    registration()->rememberTelegramMessage($player, 4243, 99);

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
        ->and($character->progress_step)->toBe(ProgressStepEnum::SET_CITY);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/deleteMessage')
            && (int) $request['message_id'] === 2;
    });

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageCaption')
            && str_contains((string) $request['caption'], 'HeroNick');
    });
});

it('starts tutorial fight from intro callback', function (): void {
    Bus::fake();

    $player = registration()->ensurePlayer(4244);
    $player = registration()->setNick($player, 'IntroFighter')->character;
    $player = registration()->setLocation($player, registration()->cities()[0]->key)->character;
    $player->progress_step = ProgressStepEnum::INTRO;
    $player->save();

    $payload = [
        'update_id' => 12,
        'callback_query' => [
            'id' => 'cb-intro-1',
            'data' => 'ob:intro_fight',
            'from' => ['id' => 4244, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 3,
                'chat' => ['id' => 4244, 'type' => 'private'],
                'text' => 'intro',
            ],
        ],
    ];

    $this->postJson('/telegram/webhook', $payload, [
        'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
    ])->assertOk();

    $player->refresh();

    expect($player->progress_step)->toBe(ProgressStepEnum::TUTORIAL_FIGHT)
        ->and(fights()->exists(4244))->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/sendPhoto')) {
            return false;
        }

        $body = $request->body();
        $attackJson = mb_substr(json_encode(__('combat.btn_attack'), JSON_THROW_ON_ERROR), 1, -1);

        return str_contains($body, 'IntroFighter')
            && str_contains($body, 'Раунд')
            && str_contains($body, $attackJson)
            && str_contains($body, 'inline_keyboard')
            && str_contains($body, 'fight:stance:ATTACK')
            && ! str_contains($body, mb_trim(__('combat.pick_stance')));
    });
});

it('resumes tutorial fight panel on /start', function (): void {
    Bus::fake();

    $player = registration()->ensurePlayer(4245);
    $player = registration()->setNick($player, 'ResumeAtk')->character;
    $player = registration()->setLocation($player, registration()->cities()[0]->key)->character;
    $fight = onboarding()->startTutorialFight($player);
    $fight->step = FightStepEnum::ATTACK;
    $fight->player_stance = StanceEnum::ATTACK;
    $fight->player_attack = null;
    $fight->save();

    $this->postJson('/telegram/webhook', [
        'update_id' => 13,
        'message' => [
            'message_id' => 4,
            'from' => ['id' => 4245, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => 4245, 'type' => 'private'],
            'text' => '/start',
        ],
    ], [
        'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
    ])->assertOk();

    $player->refresh();

    expect($player->progress_step)->toBe(ProgressStepEnum::TUTORIAL_FIGHT)
        ->and(fights()->exists(4245))->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/sendPhoto')) {
            return false;
        }

        $body = $request->body();
        $zoneJson = mb_substr(json_encode(__('combat.zone_label.HEAD'), JSON_THROW_ON_ERROR), 1, -1);
        $attackJson = mb_substr(json_encode(__('combat.btn_attack'), JSON_THROW_ON_ERROR), 1, -1);

        return str_contains($body, 'ResumeAtk')
            && str_contains($body, 'Раунд')
            && str_contains($body, $zoneJson)
            && str_contains($body, 'inline_keyboard')
            && str_contains($body, 'fight:atk:HEAD')
            && ! str_contains($body, $attackJson);
    });
});

it('falls back to intro when tutorial fight row is missing', function (): void {
    $player = registration()->ensurePlayer(4248);
    $player = registration()->setNick($player, 'NoFight')->character;
    $player = registration()->setLocation($player, registration()->cities()[0]->key)->character;
    $player->progress_step = ProgressStepEnum::TUTORIAL_FIGHT;
    $player->save();

    expect(fights()->exists(4248))->toBeFalse();

    $this->postJson('/telegram/webhook', [
        'update_id' => 16,
        'message' => [
            'message_id' => 7,
            'from' => ['id' => 4248, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => 4248, 'type' => 'private'],
            'text' => '/start',
        ],
    ], [
        'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
    ])->assertOk();

    $player->refresh();

    expect($player->progress_step)->toBe(ProgressStepEnum::INTRO);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], mb_trim(__('onboarding.intro')));
    });
});

it('telegram:poll refuses when webhook url set', function (): void {
    config(['bot.webhook_url' => 'https://example.com/telegram/webhook']);

    $this->artisan('telegram:poll')
        ->assertFailed();
});

it('TelegramClient sendMessage hits api base', function (): void {
    app(TelegramClient::class)->sendMessage(1, 'hi', null);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), 'api.telegram.org/bottest-token/sendMessage')
            && ($request['parse_mode'] ?? null) === 'HTML';
    });
});

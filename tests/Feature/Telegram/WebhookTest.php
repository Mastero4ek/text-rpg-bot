<?php

declare(strict_types=1);

use App\Enums\Combat\StanceEnum;
use App\Enums\Combat\ZoneEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\Fight\PlayerAttackEnum;
use App\Enums\OnboardingStepEnum;
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

it('starts tutorial fight from intro callback', function (): void {
    Bus::fake();

    $player = onboarding()->ensurePlayer(4244);
    $player = onboarding()->setNick($player, 'IntroFighter')->character;
    $player = onboarding()->setLocation($player, onboarding()->cities()[0]->key)->character;

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

    expect($player->onboarding_step)->toBe(OnboardingStepEnum::TUTORIAL_FIGHT)
        ->and(fights()->exists(4244))->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageText')
            && str_contains((string) $request['text'], mb_trim(__('combat.pick_stance')));
    });
});

it('resumes tutorial fight wizard step on /start', function (): void {
    Bus::fake();

    $player = onboarding()->ensurePlayer(4245);
    $player = onboarding()->setNick($player, 'ResumeAtk')->character;
    $player = onboarding()->setLocation($player, onboarding()->cities()[0]->key)->character;
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

    expect($player->onboarding_step)->toBe(OnboardingStepEnum::TUTORIAL_FIGHT)
        ->and(fights()->exists(4245))->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], mb_trim(__('combat.pick_attack')));
    });
});

it('resumes tutorial defend step on /start', function (): void {
    Bus::fake();

    $player = onboarding()->ensurePlayer(4246);
    $player = onboarding()->setNick($player, 'ResumeDef')->character;
    $player = onboarding()->setLocation($player, onboarding()->cities()[0]->key)->character;
    $fight = onboarding()->startTutorialFight($player);
    $fight->step = FightStepEnum::DEFEND;
    $fight->player_stance = StanceEnum::DEFEND;
    $fight->player_attack = PlayerAttackEnum::HEAD;
    $fight->player_defend = null;
    $fight->save();

    $this->postJson('/telegram/webhook', [
        'update_id' => 14,
        'message' => [
            'message_id' => 5,
            'from' => ['id' => 4246, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => 4246, 'type' => 'private'],
            'text' => '/start',
        ],
    ], [
        'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
    ])->assertOk();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], mb_trim(__('combat.pick_defend')));
    });
});

it('resumes tutorial second defend excluding first zone on /start', function (): void {
    Bus::fake();

    $player = onboarding()->ensurePlayer(4247);
    $player = onboarding()->setNick($player, 'ResumeShield')->character;
    $player = onboarding()->setLocation($player, onboarding()->cities()[0]->key)->character;
    $fight = onboarding()->startTutorialFight($player);
    $fight->step = FightStepEnum::DEFEND_SECOND;
    $fight->player_stance = StanceEnum::DEFEND;
    $fight->player_attack = PlayerAttackEnum::HEAD;
    $fight->player_defend = ZoneEnum::CHEST;
    $fight->save();

    $this->postJson('/telegram/webhook', [
        'update_id' => 15,
        'message' => [
            'message_id' => 6,
            'from' => ['id' => 4247, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => 4247, 'type' => 'private'],
            'text' => '/start',
        ],
    ], [
        'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
    ])->assertOk();

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/sendMessage')) {
            return false;
        }

        $text = (string) $request['text'];
        $markup = $request['reply_markup'];

        if (! is_string($markup)) {
            return false;
        }

        return str_contains($text, mb_trim(__('combat.pick_defend_second')))
            && str_contains($markup, 'fight:def:HEAD')
            && ! str_contains($markup, 'fight:def:CHEST');
    });
});

it('falls back to intro when tutorial fight row is missing', function (): void {
    $player = onboarding()->ensurePlayer(4248);
    $player = onboarding()->setNick($player, 'NoFight')->character;
    $player = onboarding()->setLocation($player, onboarding()->cities()[0]->key)->character;
    $player->onboarding_step = OnboardingStepEnum::TUTORIAL_FIGHT;
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

    expect($player->onboarding_step)->toBe(OnboardingStepEnum::INTRO);

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

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'api.telegram.org/bottest-token/sendMessage');
    });
});

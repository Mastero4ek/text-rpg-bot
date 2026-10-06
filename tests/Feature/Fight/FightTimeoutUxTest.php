<?php

declare(strict_types=1);

use App\Enums\Combat\StanceEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\OnboardingStepEnum;
use App\Jobs\ResolveFightTurnTimeoutJob;
use App\Models\Fight;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Handlers\FightHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

it('timeout job edits fight message and continues after skip', function (): void {
    Bus::fake();
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $p = characters()->createDraft(9301);
    $p->username = 'JobContinue';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 42;
    $fight->turn_deadline_at = now()->subSecond();
    $fight->save();

    fakeRandom([0.99, 0.0, 0.0, 0.0, 0.99, 0.99, 0.5]);

    app()->call([new ResolveFightTurnTimeoutJob($fight->tg_id, $fight->turn_seq), 'handle']);

    $fight->refresh();

    expect($fight->log)->toContain(__('combat.turn_timeout'))
        ->and($fight->step)->toBe(FightStepEnum::STANCE);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/editMessageText')) {
            return false;
        }

        $text = (string) $request['text'];

        return str_contains($text, __('combat.turn_timeout'))
            && str_contains($text, mb_trim(__('combat.pick_stance')));
    });
});

it('timeout job finishes win when enemy is already down on skip', function (): void {
    Bus::fake();
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $p = characters()->createDraft(9305);
    $p->onboarding_step = OnboardingStepEnum::DONE;
    $p->username = 'JobWin';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $enemy = $fight->enemy;
    $enemy['current_hp'] = 0;
    $fight->enemy = $enemy;
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 88;
    $fight->turn_deadline_at = now()->subSecond();
    $fight->save();

    fakeRandom([0.99, 0.0, 0.0]);

    app()->call([new ResolveFightTurnTimeoutJob($fight->tg_id, $fight->turn_seq), 'handle']);

    expect(Fight::query()->whereKey($p->tg_id)->exists())->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/editMessageText')) {
            return false;
        }

        return str_contains((string) $request['text'], 'Победа');
    });
});

it('timeout job finishes lose when skip hit drops player hp to zero', function (): void {
    Bus::fake();
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $p = characters()->createDraft(9302);
    $p->onboarding_step = OnboardingStepEnum::DONE;
    $p->username = 'JobLose';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->player_hp = 1;
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 77;
    $fight->turn_deadline_at = now()->subSecond();
    $fight->save();

    // No weapon roll (bare fists 0-0). Force open hit that still deals >=1 dmg.
    fakeRandom([0.99, 0.0, 0.75, 0.99, 0.99, 0.5]);

    $beforeSeq = $fight->turn_seq;

    app()->call([new ResolveFightTurnTimeoutJob($fight->tg_id, $beforeSeq), 'handle']);

    expect(Fight::query()->whereKey($p->tg_id)->exists())->toBeFalse();

    $player = characters()->findByTgId($p->tg_id);
    expect($player->current_hp)->toBe(0);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/editMessageText')) {
            return false;
        }

        return str_contains((string) $request['text'], mb_trim(__('combat.lose')));
    });
});

it('lazy-resolves timed out turn before applying stance click', function (): void {
    Bus::fake();
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $p = characters()->createDraft(9303);
    $p->onboarding_step = OnboardingStepEnum::DONE;
    $p->username = 'LazySkip';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->turn_deadline_at = now()->subSecond();
    $fight->save();

    fakeRandom([0.99, 0.0, 0.0, 0.0, 0.99, 0.99, 0.5]);

    $update = new TelegramUpdate([
        'update_id' => 9401,
        'callback_query' => [
            'id' => 'cb-lazy-1',
            'data' => 'fight:stance:ATTACK',
            'from' => ['id' => $p->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 15,
                'chat' => ['id' => $p->tg_id, 'type' => 'private'],
                'text' => 'fight',
            ],
        ],
    ]);

    app(FightHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    $fight->refresh();

    expect($fight->log)->toContain(__('combat.turn_timeout'))
        ->and($fight->player_stance)->toBeNull()
        ->and($fight->step)->toBe(FightStepEnum::STANCE)
        ->and($fight->tg_message_id)->toBe(15);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/editMessageText')) {
            return false;
        }

        $text = (string) $request['text'];

        return str_contains($text, __('combat.turn_timeout'))
            && str_contains($text, mb_trim(__('combat.pick_stance')));
    });
});

it('does not apply stance enum from timed out lazy click', function (): void {
    Bus::fake();
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $p = characters()->createDraft(9304);
    $p->onboarding_step = OnboardingStepEnum::DONE;
    $p->username = 'LazyDefend';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->turn_deadline_at = now()->subSecond();
    $fight->save();

    fakeRandom([0.99, 0.0, 0.0, 0.0, 0.99, 0.99, 0.5]);

    $update = new TelegramUpdate([
        'update_id' => 9402,
        'callback_query' => [
            'id' => 'cb-lazy-2',
            'data' => 'fight:stance:DEFEND',
            'from' => ['id' => $p->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 16,
                'chat' => ['id' => $p->tg_id, 'type' => 'private'],
                'text' => 'fight',
            ],
        ],
    ]);

    app(FightHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    $fight->refresh();

    expect($fight->player_stance)->not->toBe(StanceEnum::DEFEND)
        ->and($fight->player_stance)->toBeNull();
});

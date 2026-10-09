<?php

declare(strict_types=1);

use App\Enums\Combat\StanceEnum;
use App\Enums\Fight\FightReturnEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\ProgressStepEnum;
use App\Models\City;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Telegram\Handlers\FightHandler;
use App\Telegram\UpdateProcessor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Bus::fake();
    $id = 10;
    Http::fake(function () use (&$id) {
        $id++;

        return Http::response(['ok' => true, 'result' => ['message_id' => $id]]);
    });
});

it('commits stance from inline callback and edits single panel', function (): void {
    $p = characters()->createDraft(9601);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'ReplyHero';
    $p = placeInCity($p, City::KEY_ANKRAT);

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 1;
    $fight->tg_reply_kind = 'stance_potions';
    $fight->save();

    $update = fightInlineCallback($p->tg_id, 'fight:atk:HEAD');
    app(FightHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    $fight->refresh();
    expect($fight->step)->toBe(FightStepEnum::STANCE)
        ->and($fight->player_stance)->toBeNull();

    $update = fightInlineCallback($p->tg_id, 'fight:stance:ATTACK');
    app(FightHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    $fight->refresh();
    expect($fight->step)->toBe(FightStepEnum::ATTACK)
        ->and($fight->player_stance)->toBe(StanceEnum::ATTACK)
        ->and($fight->tg_reply_kind)->toBe('zones_atk')
        ->and($fight->tg_message_id)->toBe(1)
        ->and($fight->tg_log_message_id)->toBeNull();

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/editMessageCaption')) {
            return false;
        }

        $markup = (string) $request['reply_markup'];

        return (int) $request['message_id'] === 1
            && str_contains((string) $request['caption'], 'Выбери удар')
            && str_contains($markup, 'inline_keyboard')
            && str_contains($markup, 'fight:atk:HEAD');
    });
});

it('resolves round from defend inline callback, keeps report, sends forest anew', function (): void {
    $p = characters()->createDraft(9602);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'RoundReply';
    $p = placeInCity($p, City::KEY_ANKRAT);

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 1;
    $fight->tg_reply_kind = 'zones_def';
    $fight->player_stance = StanceEnum::ATTACK;
    $fight->player_attack = App\Enums\Fight\PlayerAttackEnum::HEAD;
    $fight->step = FightStepEnum::DEFEND;
    $fight->player_hp = 60;
    $enemy = $fight->enemy;
    $enemy['current_hp'] = 1;
    $fight->enemy = $enemy;
    $fight->save();

    fakeRandom([0.99, 0.0, 0.0, 0.0, 0.99, 0.99, 0.5, 0.5, 0.5, 0.5]);

    $update = fightInlineCallback($p->tg_id, 'fight:def:CHEST');
    app(FightHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    expect(fights()->exists($p->tg_id))->toBeFalse();

    $p->refresh();
    expect($p->fight_return)->toBeNull();

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/editMessageCaption')) {
            return false;
        }

        return (int) $request['message_id'] === 1
            && str_contains((string) $request['caption'], 'Победа')
            && ! str_contains((string) $request['reply_markup'], 'fight:back');
    });

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/sendPhoto') && ! str_contains($request->url(), '/sendMessage')) {
            return false;
        }

        return str_contains($request->body(), 'Сучья смыкаются')
            || str_contains($request->body(), 'тишина');
    });
});

it('routes fight inline callback through update processor', function (): void {
    $p = characters()->createDraft(9603);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'RouteHero';
    $p = placeInCity($p, City::KEY_ANKRAT);

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 1;
    $fight->save();

    app(UpdateProcessor::class)->handle([
        'update_id' => 9603,
        'callback_query' => [
            'id' => 'cb-9603',
            'data' => 'fight:stance:DEFEND',
            'from' => ['id' => $p->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => $p->tg_id, 'type' => 'private'],
                'caption' => 'fight',
            ],
        ],
    ]);

    $fight->refresh();
    expect($fight->step)->toBe(FightStepEnum::ATTACK)
        ->and($fight->player_stance)->toBe(StanceEnum::DEFEND);
});

it('auto-returns to forest after win without fight:back', function (): void {
    $p = characters()->createDraft(9604);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'BackHero';
    $p = placeInCity($p, City::KEY_ANKRAT);

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 1;
    $fight->player_stance = StanceEnum::ATTACK;
    $fight->player_attack = App\Enums\Fight\PlayerAttackEnum::HEAD;
    $fight->step = FightStepEnum::DEFEND;
    $enemy = $fight->enemy;
    $enemy['current_hp'] = 1;
    $fight->enemy = $enemy;
    $fight->save();

    expect($p->fresh()->fight_return)->toBe(FightReturnEnum::Forest);

    fakeRandom([0.99, 0.0, 0.0, 0.0, 0.99, 0.99, 0.5, 0.5, 0.5, 0.5]);

    $update = fightInlineCallback($p->tg_id, 'fight:def:LEGS');
    app(FightHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    $p->refresh();
    expect($p->fight_return)->toBeNull()
        ->and(fights()->exists($p->tg_id))->toBeFalse();
});

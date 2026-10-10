<?php

declare(strict_types=1);

use App\Enums\Fight\FightReturnEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\ProgressStepEnum;
use App\Models\City;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Telegram\Handlers\FightHandler;
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

it('flees forest fight on success and keeps hp without gems break copy', function (): void {
    $p = characters()->createDraft(9801);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'FleeOk';
    $p = placeInCity($p, City::KEY_ANKRAT);
    $silverBefore = $p->silver;
    $expBefore = $p->exp;

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 1;
    $fight->tg_reply_kind = 'stance_potions';
    $fight->player_hp = 42;
    $fight->player_max_hp = 60;
    $fight->player_stamina = 11;
    $fight->player_max_stamina = 18;
    $fight->save();

    expect($p->fresh()->fight_return)->toBe(FightReturnEnum::Forest);

    fakeRandom([0.0]);

    $update = fightInlineCallback($p->tg_id, 'fight:flee');
    app(FightHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    $p->refresh();

    expect(fights()->exists($p->tg_id))->toBeFalse()
        ->and($p->fight_return)->toBeNull()
        ->and($p->current_hp)->toBe(42)
        ->and($p->current_stamina)->toBe(11)
        ->and($p->silver)->toBe($silverBefore)
        ->and($p->exp)->toBe($expBefore);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageCaption')
            && (int) $request['message_id'] === 1
            && str_contains((string) $request['caption'], mb_trim(__('combat.flee')))
            && ! str_contains((string) $request['caption'], mb_trim(__('combat.flee_fail')))
            && ! str_contains((string) $request['caption'], mb_trim(__('combat.lose')))
            && ! str_contains((string) $request['reply_markup'], 'fight:flee');
    });

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/sendPhoto')) {
            return false;
        }

        $body = $request->body();

        return str_contains($body, 'forest.png')
            && str_contains($body, mb_trim(__('telegram.location.forest')));
    });
});

it('keeps flee_fail visible when flee fail skip round kills the player', function (): void {
    $p = characters()->createDraft(9806);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'FleeDie';
    $p = placeInCity($p, City::KEY_ANKRAT);

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 1;
    $fight->tg_reply_kind = 'stance_potions';
    $fight->player_hp = 1;
    $fight->player_max_hp = 60;
    $enemy = $fight->enemy;
    $enemy['current_hp'] = 500;
    $enemy['maxHp'] = 500;
    $fight->enemy = $enemy;
    $fight->save();

    // 1st float: flee roll fail; rest: skip-round open hit that kills at 1 HP.
    fakeRandom([0.99, 0.99, 0.0, 0.75, 0.99, 0.99, 0.5]);

    $update = fightInlineCallback($p->tg_id, 'fight:flee');
    app(FightHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    $p->refresh();

    expect(fights()->exists($p->tg_id))->toBeFalse()
        ->and($p->current_hp)->toBe(0);

    Http::assertSent(function (Request $request): bool {
        $caption = (string) ($request['caption'] ?? '');

        return str_contains($request->url(), '/editMessageCaption')
            && (int) $request['message_id'] === 1
            && str_contains($caption, mb_trim(__('combat.flee_fail')))
            && str_contains($caption, mb_trim(__('combat.lose')));
    });
});

it('keeps fight alive on flee fail and shows flee_fail then stance', function (): void {
    $p = characters()->createDraft(9802);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'FleeFail';
    $p = placeInCity($p, City::KEY_ANKRAT);

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 1;
    $fight->tg_reply_kind = 'stance_potions';
    $fight->player_hp = 200;
    $fight->player_max_hp = 200;
    $enemy = $fight->enemy;
    $enemy['current_hp'] = 500;
    $enemy['maxHp'] = 500;
    $fight->enemy = $enemy;
    $fight->save();

    fakeRandom([0.99, 0.99, 0.0, 0.0, 0.0, 0.99, 0.99, 0.5]);

    $update = fightInlineCallback($p->tg_id, 'fight:flee');
    app(FightHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    $fight->refresh();

    expect(fights()->exists($p->tg_id))->toBeTrue()
        ->and($fight->step)->toBe(FightStepEnum::STANCE)
        ->and($fight->log)->toContain(__('combat.flee_fail'))
        ->and($fight->log)->not->toContain(__('combat.turn_timeout'));

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageCaption')
            && (int) $request['message_id'] === 1
            && str_contains((string) $request['caption'], mb_trim(__('combat.flee_fail')))
            && str_contains((string) $request['reply_markup'], 'fight:flee')
            && str_contains((string) $request['reply_markup'], 'fight:stance:ATTACK');
    });
});

it('rejects flee in tutorial fight', function (): void {
    $p = characters()->createDraft(9803);
    $p->progress_step = ProgressStepEnum::TUTORIAL_FIGHT;
    $p->username = 'FleeTut';
    $p->save();

    $fight = fights()->createTutorial($p, woodenSoldier($p));
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 1;
    $fight->save();

    fakeRandom([0.0]);

    $update = fightInlineCallback($p->tg_id, 'fight:flee');
    app(FightHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    expect(fights()->exists($p->tg_id))->toBeTrue();

    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageCaption')
            && str_contains((string) ($request['caption'] ?? ''), mb_trim(__('combat.flee')));
    });
});

it('rejects flee when fight is not on stance step', function (): void {
    $p = characters()->createDraft(9804);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'FleeAtk';
    $p = placeInCity($p, City::KEY_ANKRAT);

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 1;
    $fight->step = FightStepEnum::ATTACK;
    $fight->save();

    fakeRandom([0.0]);

    $update = fightInlineCallback($p->tg_id, 'fight:flee');
    app(FightHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    $fight->refresh();

    expect(fights()->exists($p->tg_id))->toBeTrue()
        ->and($fight->step)->toBe(FightStepEnum::ATTACK);

    Http::assertNotSent(function (Request $request): bool {
        $caption = (string) ($request['caption'] ?? '');

        return str_contains($request->url(), '/editMessageCaption')
            && (
                str_contains($caption, mb_trim(__('combat.flee')))
                || str_contains($caption, mb_trim(__('combat.flee_fail')))
            );
    });
});

it('flees hall fight without zeroing hp', function (): void {
    $soldier = App\Models\Enemy\EnemyCatalog::query()->findOrFail(App\Models\Enemy\EnemyCatalog::TUTORIAL_CATALOG_ID);
    $soldier->cities()->sync([]);
    $kurgan = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
    $soldier->trainingCities()->sync([$kurgan->id]);

    $p = characters()->createDraft(9805);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'FleeHall';
    $p = placeInCity($p, City::KEY_ELDWOOD);

    $fight = fights()->createHall($p, woodenSoldier($p));
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 1;
    $fight->player_hp = 33;
    $fight->player_max_hp = 60;
    $fight->player_stamina = 9;
    $fight->player_max_stamina = 18;
    $fight->save();

    expect($p->fresh()->fight_return)->toBe(FightReturnEnum::Training);

    fakeRandom([0.0]);

    $update = fightInlineCallback($p->tg_id, 'fight:flee');
    app(FightHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    $p->refresh();

    expect(fights()->exists($p->tg_id))->toBeFalse()
        ->and($p->current_hp)->toBe(33)
        ->and($p->current_stamina)->toBe(9)
        ->and($p->fight_return)->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageCaption')
            && str_contains((string) $request['caption'], mb_trim(__('combat.flee')));
    });
});

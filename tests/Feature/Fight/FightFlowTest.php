<?php

declare(strict_types=1);

use App\Enums\Combat\StanceEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Fight\FightReturnEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\Fight\PlayerAttackEnum;
use App\Enums\ProgressStepEnum;
use App\Models\City;
use App\Models\Enemy\EnemyCatalog;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Telegram\Handlers\CityHandler;
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

describe('positive fight flow', function (): void {
    it('plays full forest turn on one panel and continues after survive', function (): void {
        $p = characters()->createDraft(9701);
        $p->progress_step = ProgressStepEnum::DONE;
        $p->username = 'FlowForest';
        $p = placeInCity($p, City::KEY_ANKRAT);

        $fight = fights()->createTraining($p, woodenSoldier($p));
        $fight->tg_chat_id = $p->tg_id;
        $fight->tg_message_id = 1;
        $fight->tg_reply_kind = 'stance_potions';
        $enemy = $fight->enemy;
        $enemy['current_hp'] = 500;
        $enemy['maxHp'] = 500;
        $fight->enemy = $enemy;
        $fight->player_hp = 200;
        $fight->player_max_hp = 200;
        $fight->save();

        fakeRandom([0.99, 0.0, 0.75, 0.99, 0.99, 0.5, 0.99, 0.0, 0.75, 0.99, 0.99, 0.5]);

        $handler = app(FightHandler::class);

        $handler->handleCallback(
            fightInlineCallback($p->tg_id, 'fight:stance:ATTACK'),
            new TelegramResponder(app(TelegramClient::class), fightInlineCallback($p->tg_id, 'fight:stance:ATTACK')),
        );
        $fight->refresh();
        expect($fight->step)->toBe(FightStepEnum::ATTACK)
            ->and($fight->player_stance)->toBe(StanceEnum::ATTACK)
            ->and($fight->tg_message_id)->toBe(1);

        $handler->handleCallback(
            fightInlineCallback($p->tg_id, 'fight:atk:HEAD'),
            new TelegramResponder(app(TelegramClient::class), fightInlineCallback($p->tg_id, 'fight:atk:HEAD')),
        );
        $fight->refresh();
        expect($fight->step)->toBe(FightStepEnum::DEFEND)
            ->and($fight->player_attack)->toBe(PlayerAttackEnum::HEAD)
            ->and($fight->tg_message_id)->toBe(1);

        $handler->handleCallback(
            fightInlineCallback($p->tg_id, 'fight:def:CHEST'),
            new TelegramResponder(app(TelegramClient::class), fightInlineCallback($p->tg_id, 'fight:def:CHEST')),
        );
        $fight->refresh();

        expect(fights()->exists($p->tg_id))->toBeTrue()
            ->and($fight->step)->toBe(FightStepEnum::STANCE)
            ->and($fight->tg_message_id)->toBe(1)
            ->and($fight->tg_reply_kind)->toBe('stance_potions');

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/editMessageCaption')
                && (int) $request['message_id'] === 1
                && str_contains((string) $request['caption'], 'Раунд')
                && str_contains((string) $request['reply_markup'], 'fight:stance:ATTACK');
        });
    });

    it('wins hall fight and returns training pick with arena photo', function (): void {
        $soldier = EnemyCatalog::query()->findOrFail(EnemyCatalog::TUTORIAL_CATALOG_ID);
        $soldier->cities()->sync([]);
        $kurgan = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
        $soldier->trainingCities()->sync([$kurgan->id]);

        $p = characters()->createDraft(9702);
        $p->progress_step = ProgressStepEnum::DONE;
        $p->username = 'FlowHallWin';
        $p = placeInCity($p, City::KEY_ELDWOOD);

        $fight = fights()->createHall($p, woodenSoldier($p));
        $fight->tg_chat_id = $p->tg_id;
        $fight->tg_message_id = 1;
        $fight->tg_reply_kind = 'zones_def';
        $fight->player_stance = StanceEnum::ATTACK;
        $fight->player_attack = PlayerAttackEnum::HEAD;
        $fight->step = FightStepEnum::DEFEND;
        $fight->player_hp = 80;
        $enemy = $fight->enemy;
        $enemy['current_hp'] = 1;
        $fight->enemy = $enemy;
        $fight->save();

        expect($p->fresh()->fight_return)->toBe(FightReturnEnum::Training);

        fakeRandom([0.99, 0.0, 0.0, 0.0, 0.99, 0.99, 0.5, 0.5, 0.5, 0.5]);

        app(FightHandler::class)->handleCallback(
            fightInlineCallback($p->tg_id, 'fight:def:LEGS'),
            new TelegramResponder(app(TelegramClient::class), fightInlineCallback($p->tg_id, 'fight:def:LEGS')),
        );

        $p->refresh();
        expect(fights()->exists($p->tg_id))->toBeFalse()
            ->and($p->fight_return)->toBeNull();

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/editMessageCaption')) {
                return false;
            }

            return (int) $request['message_id'] === 1
                && str_contains((string) $request['caption'], 'Победа')
                && ! str_contains((string) $request['reply_markup'], 'fight:back');
        });

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/sendPhoto')) {
                return false;
            }

            $body = $request->body();

            return str_contains($body, 'arena.png')
                && str_contains($body, mb_trim(__('telegram.location.training')))
                && str_contains($body, 'city:training:start:' . EnemyCatalog::TUTORIAL_CATALOG_ID);
        });
    });

    it('loses hall fight at zero hp and still returns training list with arena photo', function (): void {
        $soldier = EnemyCatalog::query()->findOrFail(EnemyCatalog::TUTORIAL_CATALOG_ID);
        $soldier->cities()->sync([]);
        $kurgan = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
        $soldier->trainingCities()->sync([$kurgan->id]);

        $p = characters()->createDraft(9703);
        $p->progress_step = ProgressStepEnum::DONE;
        $p->username = 'FlowHallLose';
        $p = placeInCity($p, City::KEY_ELDWOOD);

        $fight = fights()->createHall($p, woodenSoldier($p));
        $fight->tg_chat_id = $p->tg_id;
        $fight->tg_message_id = 1;
        $fight->player_stance = StanceEnum::DEFEND;
        $fight->player_attack = PlayerAttackEnum::HEAD;
        $fight->step = FightStepEnum::DEFEND;
        $fight->player_hp = 1;
        $enemy = $fight->enemy;
        $enemy['current_hp'] = 500;
        $fight->enemy = $enemy;
        $fight->save();

        fakeRandom([0.99, 0.0, 0.75, 0.99, 0.99, 0.5, 0.99, 0.0, 0.75, 0.99, 0.99, 0.5]);

        app(FightHandler::class)->handleCallback(
            fightInlineCallback($p->tg_id, 'fight:def:CHEST'),
            new TelegramResponder(app(TelegramClient::class), fightInlineCallback($p->tg_id, 'fight:def:CHEST')),
        );

        $p->refresh();
        expect(fights()->exists($p->tg_id))->toBeFalse()
            ->and($p->current_hp)->toBe(0)
            ->and($p->fight_return)->toBeNull();

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/editMessageCaption')
                && str_contains((string) $request['caption'], mb_trim(__('combat.lose')))
                && ! str_contains((string) $request['reply_markup'], 'fight:back');
        });

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/sendPhoto')) {
                return false;
            }

            $body = $request->body();

            return str_contains($body, 'arena.png')
                && str_contains($body, mb_trim(__('telegram.location.training')))
                && str_contains($body, 'city:training:start:' . EnemyCatalog::TUTORIAL_CATALOG_ID);
        });
    });

    it('starts hall fight from city callback with panel and training return', function (): void {
        $soldier = EnemyCatalog::query()->findOrFail(EnemyCatalog::TUTORIAL_CATALOG_ID);
        $soldier->cities()->sync([]);
        $kurgan = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
        $soldier->trainingCities()->sync([$kurgan->id]);

        $p = characters()->createDraft(9704);
        $p->progress_step = ProgressStepEnum::DONE;
        $p->username = 'FlowHallStart';
        $p = placeInCity($p, City::KEY_ELDWOOD);

        $update = cityPhotoCallback($p->tg_id, 'city:training:start:' . EnemyCatalog::TUTORIAL_CATALOG_ID);
        app(CityHandler::class)->handleCallback(
            $update,
            new TelegramResponder(app(TelegramClient::class), $update),
        );

        $fight = fights()->findByTgId($p->tg_id);
        $p->refresh();

        expect($fight->hall)->toBeTrue()
            ->and($fight->return_to)->toBe(FightReturnEnum::Training)
            ->and($p->fight_return)->toBe(FightReturnEnum::Training)
            ->and($fight->tg_message_id)->not->toBeNull()
            ->and($fight->tg_reply_kind)->toBe('stance_potions');

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/editMessageMedia')
                && str_contains($request->body(), 'fight:stance:ATTACK')
                && str_contains($request->body(), 'Раунд');
        });
    });

    it('drinks heal potion from stance and advances to defend on same panel', function (): void {
        $p = giveAndEquipStarterKnuckles(characters()->createDraft(9705));
        $p->progress_step = ProgressStepEnum::DONE;
        $p->username = 'FlowPotion';
        $p->silver = bagCatalog()->potionPrice();
        $p = placeInCity($p, City::KEY_ANKRAT);
        buyerService()->buyPotion($p->tg_id);

        expect(bag()->potionCountByProfile($p->tg_id, ProfileEnum::HEAL))->toBe(1);

        $fight = fights()->createTraining($p->fresh(), woodenSoldier($p));
        $fight->tg_chat_id = $p->tg_id;
        $fight->tg_message_id = 1;
        $fight->tg_reply_kind = 'stance_potions';
        $fight->save();

        app(FightHandler::class)->handleCallback(
            fightInlineCallback($p->tg_id, 'fight:atk:POTION'),
            new TelegramResponder(app(TelegramClient::class), fightInlineCallback($p->tg_id, 'fight:atk:POTION')),
        );

        $fight->refresh();
        expect($fight->step)->toBe(FightStepEnum::DEFEND)
            ->and($fight->use_potion)->toBeTrue()
            ->and($fight->player_attack)->toBe(PlayerAttackEnum::POTION)
            ->and($fight->player_stance)->toBe(StanceEnum::DEFEND)
            ->and($fight->tg_message_id)->toBe(1)
            ->and($fight->tg_reply_kind)->toBe('zones_def');

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/editMessageCaption')
                && (int) $request['message_id'] === 1
                && str_contains((string) $request['reply_markup'], 'fight:def:HEAD')
                && ! str_contains((string) $request['caption'], mb_trim(__('errors.potion_unavailable')));
        });
    });
});

describe('negative fight flow', function (): void {
    it('rejects hall start without hp', function (): void {
        $soldier = EnemyCatalog::query()->findOrFail(EnemyCatalog::TUTORIAL_CATALOG_ID);
        $soldier->cities()->sync([]);
        $kurgan = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
        $soldier->trainingCities()->sync([$kurgan->id]);

        $p = characters()->createDraft(9711);
        $p->progress_step = ProgressStepEnum::DONE;
        $p->username = 'FlowNoHp';
        $p->current_hp = 0;
        $p = placeInCity($p, City::KEY_ELDWOOD);

        $update = cityCallback($p->tg_id, 'city:training:start:' . EnemyCatalog::TUTORIAL_CATALOG_ID);
        app(CityHandler::class)->handleCallback(
            $update,
            new TelegramResponder(app(TelegramClient::class), $update),
        );

        expect(fights()->exists($p->tg_id))->toBeFalse();

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/editMessageText')
                && str_contains((string) $request['text'], mb_trim(__('telegram.location.error.no_hp')));
        });
    });

    it('rejects hall start for unknown training enemy', function (): void {
        $p = characters()->createDraft(9712);
        $p->progress_step = ProgressStepEnum::DONE;
        $p->username = 'FlowBadEnemy';
        $p = placeInCity($p, City::KEY_ELDWOOD);

        $update = cityCallback($p->tg_id, 'city:training:start:missing_mob');
        app(CityHandler::class)->handleCallback(
            $update,
            new TelegramResponder(app(TelegramClient::class), $update),
        );

        expect(fights()->exists($p->tg_id))->toBeFalse();

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/editMessageText')
                && str_contains((string) $request['text'], mb_trim(__('telegram.location.error.enemy_not_found')));
        });
    });

    it('shows potion denied error in fight caption without new reply', function (): void {
        $p = characters()->createDraft(9713);
        $p->progress_step = ProgressStepEnum::DONE;
        $p->username = 'FlowNoPotion';
        $p = placeInCity($p, City::KEY_ANKRAT);

        expect(bag()->potionCountByProfile($p->tg_id, ProfileEnum::HEAL))->toBe(0);

        $fight = fights()->createTraining($p, woodenSoldier($p));
        $fight->tg_chat_id = $p->tg_id;
        $fight->tg_message_id = 1;
        $fight->tg_reply_kind = 'stance_potions';
        $fight->save();

        Http::fake(function () {
            return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
        });

        app(FightHandler::class)->handleCallback(
            fightInlineCallback($p->tg_id, 'fight:atk:POTION'),
            new TelegramResponder(app(TelegramClient::class), fightInlineCallback($p->tg_id, 'fight:atk:POTION')),
        );

        $fight->refresh();
        expect($fight->step)->toBe(FightStepEnum::STANCE)
            ->and($fight->use_potion)->toBeFalse()
            ->and($fight->tg_message_id)->toBe(1);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/editMessageCaption')
                && (int) $request['message_id'] === 1
                && str_contains((string) $request['caption'], mb_trim(strip_tags(__('errors.potion_unavailable'))))
                && str_contains((string) $request['reply_markup'], 'fight:stance:ATTACK');
        });

        Http::assertNotSent(function (Request $request): bool {
            return str_contains($request->url(), '/sendMessage')
                && str_contains((string) ($request['text'] ?? ''), mb_trim(strip_tags(__('errors.potion_unavailable'))));
        });
    });

    it('denies potion in tutorial fight via caption error', function (): void {
        $p = giveAndEquipStarterKnuckles(characters()->createDraft(9714));
        $p->progress_step = ProgressStepEnum::DONE;
        $p->username = 'FlowTutPotion';
        $p->silver = bagCatalog()->potionPrice();
        $p = placeInCity($p, City::KEY_ANKRAT);
        buyerService()->buyPotion($p->tg_id);

        $fight = fights()->createTutorial($p->fresh(), woodenSoldier($p));
        $fight->tg_chat_id = $p->tg_id;
        $fight->tg_message_id = 1;
        $fight->save();

        expect($fight->tutorial)->toBeTrue();

        app(FightHandler::class)->handleCallback(
            fightInlineCallback($p->tg_id, 'fight:atk:POTION'),
            new TelegramResponder(app(TelegramClient::class), fightInlineCallback($p->tg_id, 'fight:atk:POTION')),
        );

        $fight->refresh();
        expect($fight->step)->toBe(FightStepEnum::STANCE)
            ->and($fight->use_potion)->toBeFalse();

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/editMessageCaption')
                && str_contains((string) $request['caption'], mb_trim(strip_tags(__('errors.potion_unavailable'))));
        });
    });

    it('ignores stance callback when step is not stance', function (): void {
        $p = characters()->createDraft(9715);
        $p->progress_step = ProgressStepEnum::DONE;
        $p->username = 'FlowBadStance';
        $p = placeInCity($p, City::KEY_ANKRAT);

        $fight = fights()->createTraining($p, woodenSoldier($p));
        $fight->tg_chat_id = $p->tg_id;
        $fight->tg_message_id = 1;
        $fight->step = FightStepEnum::ATTACK;
        $fight->player_stance = StanceEnum::ATTACK;
        $fight->save();

        Http::fake(function () {
            return Http::response(['ok' => true, 'result' => ['message_id' => 99]]);
        });

        app(FightHandler::class)->handleCallback(
            fightInlineCallback($p->tg_id, 'fight:stance:DEFEND'),
            new TelegramResponder(app(TelegramClient::class), fightInlineCallback($p->tg_id, 'fight:stance:DEFEND')),
        );

        $fight->refresh();
        expect($fight->step)->toBe(FightStepEnum::ATTACK)
            ->and($fight->player_stance)->toBe(StanceEnum::ATTACK);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/editMessageCaption'));
    });

    it('ignores attack zone when step is stance', function (): void {
        $p = characters()->createDraft(9716);
        $p->progress_step = ProgressStepEnum::DONE;
        $p->username = 'FlowBadAtk';
        $p = placeInCity($p, City::KEY_ANKRAT);

        $fight = fights()->createTraining($p, woodenSoldier($p));
        $fight->tg_chat_id = $p->tg_id;
        $fight->tg_message_id = 1;
        $fight->step = FightStepEnum::STANCE;
        $fight->save();

        Http::fake(function () {
            return Http::response(['ok' => true, 'result' => ['message_id' => 99]]);
        });

        app(FightHandler::class)->handleCallback(
            fightInlineCallback($p->tg_id, 'fight:atk:HEAD'),
            new TelegramResponder(app(TelegramClient::class), fightInlineCallback($p->tg_id, 'fight:atk:HEAD')),
        );

        $fight->refresh();
        expect($fight->step)->toBe(FightStepEnum::STANCE)
            ->and($fight->player_attack)->toBeNull();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/editMessageCaption'));
    });

    it('ignores defend when no active fight', function (): void {
        $p = characters()->createDraft(9717);
        $p->progress_step = ProgressStepEnum::DONE;
        $p->username = 'FlowNoFight';
        $p = placeInCity($p, City::KEY_ANKRAT);

        expect(fights()->exists($p->tg_id))->toBeFalse();

        Http::fake(function () {
            return Http::response(['ok' => true, 'result' => ['message_id' => 99]]);
        });

        app(FightHandler::class)->handleCallback(
            fightInlineCallback($p->tg_id, 'fight:def:HEAD'),
            new TelegramResponder(app(TelegramClient::class), fightInlineCallback($p->tg_id, 'fight:def:HEAD')),
        );

        expect(fights()->exists($p->tg_id))->toBeFalse();
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/editMessageCaption'));
    });

    it('rejects training pick without hp', function (): void {
        $p = characters()->createDraft(9718);
        $p->progress_step = ProgressStepEnum::DONE;
        $p->username = 'FlowPickNoHp';
        $p->current_hp = 0;
        $p = placeInCity($p, City::KEY_ELDWOOD);

        $update = cityCallback($p->tg_id, 'city:training');
        app(CityHandler::class)->handleCallback(
            $update,
            new TelegramResponder(app(TelegramClient::class), $update),
        );

        expect(fights()->exists($p->tg_id))->toBeFalse();

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/editMessageText')
                && str_contains((string) $request['text'], mb_trim(__('telegram.location.error.no_hp')));
        });
    });
});

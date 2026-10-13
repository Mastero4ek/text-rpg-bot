<?php

declare(strict_types=1);

use App\Actions\Character\CharacterApplyQuestScreenAction;
use App\Enums\ProgressStepEnum;
use App\Enums\Quest\QuestChapterEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\City;
use App\Models\Fight;
use App\Quests\BuyerQuest;
use App\Quests\Chapter1\Chapter1QuestModule;
use App\Quests\Chapter1\Chapter1ScreenEnum;
use App\Quests\HealerQuest;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('chains splash through chapter1_done via apply action', function (): void {
    $player = characters()->createDraft(88501);
    $player->username = null;
    $player->city_id = null;
    $player->progress_step = ProgressStepEnum::SPLASH;
    $player->save();

    $apply = app(CharacterApplyQuestScreenAction::class);
    $module = app(Chapter1QuestModule::class);
    $amuletId = app(BuyerQuest::class)->amuletCatalogId();
    $purseId = app(BuyerQuest::class)->purseCatalogId();

    $chain = [
        Chapter1ScreenEnum::RegistrationSplash,
        Chapter1ScreenEnum::RegistrationSetNick,
        Chapter1ScreenEnum::RegistrationSetSkin,
        Chapter1ScreenEnum::RegistrationSetCity,
    ];

    foreach ($chain as $screen) {
        $result = $apply->handle($player->fresh(), QuestChapterEnum::Chapter1, $screen->value);
        expect($result->ok)->toBeTrue($screen->value);
        $player = $player->fresh();
        expect($module->detect($player))->toBe($screen);
    }

    $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
    $player->city_id = $city->id;
    $player->birth_city_id = $city->id;
    $player->username = 'ChainHero';
    $player->save();

    $cityChain = [
        Chapter1ScreenEnum::OverseerGates,
        Chapter1ScreenEnum::OverseerTavern,
        Chapter1ScreenEnum::OverseerIntro,
        Chapter1ScreenEnum::OverseerAwaitFight,
        Chapter1ScreenEnum::OverseerStats,
        Chapter1ScreenEnum::HealerMeet,
        Chapter1ScreenEnum::HealerPotionLock,
        Chapter1ScreenEnum::BlacksmithMeet,
        Chapter1ScreenEnum::BuyerMeet,
        Chapter1ScreenEnum::BruiserArrive,
        Chapter1ScreenEnum::HealerDebt,
        Chapter1ScreenEnum::HealerRatLoot,
        Chapter1ScreenEnum::BruiserKnucklePending,
        Chapter1ScreenEnum::BruiserReadyForPurse,
        Chapter1ScreenEnum::BuyerTurnIn,
        Chapter1ScreenEnum::Chapter1Done,
    ];

    foreach ($cityChain as $screen) {
        if (
            $screen === Chapter1ScreenEnum::BruiserReadyForPurse
            && bag()->ownsQuestItem($player->tg_id, $purseId)
        ) {
            bag()->removeQuestItem($player->tg_id, $purseId);
        }

        $result = $apply->handle($player->fresh(), QuestChapterEnum::Chapter1, $screen->value);
        expect($result->ok)->toBeTrue($screen->value);
        $player = $player->fresh();

        if ($screen === Chapter1ScreenEnum::BuyerMeet) {
            expect(backpack()->owns($player->tg_id, $amuletId))->toBeTrue();
        }

        if ($screen === Chapter1ScreenEnum::BuyerTurnIn) {
            expect(bag()->ownsQuestItem($player->tg_id, $purseId))->toBeTrue();
        }

        expect($module->detect($player))->toBe($screen, $screen->value)
            ->and($player->quest_chapter)->toBe(QuestChapterEnum::Chapter1);
    }

    expect($player->quest_phase)->toBe(QuestPhaseEnum::Done)
        ->and($player->city_unlocked_full)->toBeTrue()
        ->and($player->unlock_portal)->toBeTrue()
        ->and($player->unlock_ruins)->toBeTrue()
        ->and($player->unlock_forest)->toBeTrue()
        ->and($player->unlock_board)->toBeTrue()
        ->and($player->unlock_arena)->toBeTrue()
        ->and($player->quest_chapter)->toBe(QuestChapterEnum::Chapter1);
});

it('clears fight row on overseer_await_fight', function (): void {
    $player = cityArrived(88502, 'ChainFight');
    fights()->createTraining($player, woodenSoldier($player));

    expect(Fight::query()->whereKey($player->tg_id)->exists())->toBeTrue();

    $result = app(CharacterApplyQuestScreenAction::class)->handle(
        $player,
        QuestChapterEnum::Chapter1,
        Chapter1ScreenEnum::OverseerAwaitFight->value,
    );

    expect($result->ok)->toBeTrue()
        ->and(Fight::query()->whereKey($player->tg_id)->exists())->toBeFalse()
        ->and(app(Chapter1QuestModule::class)->detect($player->fresh()))
        ->toBe(Chapter1ScreenEnum::OverseerAwaitFight);
});

it('detects ready without purse and turn_in with purse', function (): void {
    $player = cityDone(88503, 'ChainPurse');
    $module = app(Chapter1QuestModule::class);
    $apply = app(CharacterApplyQuestScreenAction::class);
    $purseId = app(BuyerQuest::class)->purseCatalogId();

    $apply->handle($player, QuestChapterEnum::Chapter1, Chapter1ScreenEnum::BruiserReadyForPurse->value);
    $ready = $player->fresh();

    expect(bag()->ownsQuestItem($ready->tg_id, $purseId))->toBeFalse()
        ->and($module->detect($ready))->toBe(Chapter1ScreenEnum::BruiserReadyForPurse);

    $apply->handle($ready, QuestChapterEnum::Chapter1, Chapter1ScreenEnum::BuyerTurnIn->value);
    $turnIn = $ready->fresh();

    expect(bag()->ownsQuestItem($turnIn->tg_id, $purseId))->toBeTrue()
        ->and($module->detect($turnIn))->toBe(Chapter1ScreenEnum::BuyerTurnIn);
});

it('keeps potion soft-lock after healer_potion_lock on city home callback', function (): void {
    $player = cityArrived(88504, 'ChainLock');
    $player->tg_chat_id = 88504;
    $player->tg_message_id = 9;
    $player->save();

    app(CharacterApplyQuestScreenAction::class)->handle(
        $player,
        QuestChapterEnum::Chapter1,
        Chapter1ScreenEnum::HealerPotionLock->value,
    );

    $locked = $player->fresh();

    expect(app(HealerQuest::class)->isPotionLocked($locked))->toBeTrue()
        ->and(app(Chapter1QuestModule::class)->detect($locked))->toBe(Chapter1ScreenEnum::HealerPotionLock);

    cityFlowCallback($locked->tg_id, 9, 'city:home');

    expect($locked->fresh()->healer_meet_open)->toBeTrue()
        ->and($locked->fresh()->quest_phase)->toBe(QuestPhaseEnum::Healer);

    assertCityEditMarkupHas('quest:healer:take');
});

it('returns unknown detect for broken flag mask', function (): void {
    $player = cityDone(88505, 'ChainUnk');
    $player->quest_phase = QuestPhaseEnum::Bruiser;
    $player->buyer_at_tavern = false;
    $player->bruiser_contract_active = false;
    $player->bruiser_knuckle_pending = false;
    $player->healer_debt_open = false;
    $player->healer_rat_loot_pending = false;
    $player->city_unlocked_full = false;
    $player->save();

    expect(app(Chapter1QuestModule::class)->detect($player->fresh()))->toBeNull();
});

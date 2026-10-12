<?php

declare(strict_types=1);

use App\Enums\Quest\QuestChapterEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Quests\BuyerQuest;
use App\Quests\Chapter1\Chapter1QuestModule;
use App\Quests\Chapter1\Chapter1ScreenEnum;
use App\Quests\Chapter1\Chapter1ScreenProjector;
use App\Quests\QuestChapterRegistry;

it('registry returns chapter1 module', function (): void {
    $module = app(QuestChapterRegistry::class)->module(QuestChapterEnum::Chapter1);

    expect($module)->toBeInstanceOf(Chapter1QuestModule::class)
        ->and($module->chapter())->toBe(QuestChapterEnum::Chapter1);
});

it('round-trips every mvp chapter1 screen through detect', function (): void {
    $player = cityDone(88201, 'ScrRound');
    $module = app(Chapter1QuestModule::class);

    foreach (Chapter1ScreenEnum::cases() as $screen) {
        $module->applySnapshot($player, $screen);
        $player->save();
        $player->refresh();

        expect($module->detect($player))->toBe($screen, $screen->value)
            ->and($player->quest_chapter)->toBe(QuestChapterEnum::Chapter1);
    }
});

it('applies healer_meet mask', function (): void {
    $player = cityArrived(88202, 'ScrHealer');
    app(Chapter1ScreenProjector::class)->apply($player, Chapter1ScreenEnum::HealerMeet);
    $player->save();
    $fresh = $player->fresh();

    expect($fresh->quest_phase)->toBe(QuestPhaseEnum::Healer)
        ->and($fresh->unlock_portal)->toBeTrue()
        ->and($fresh->unlock_forest)->toBeFalse()
        ->and($fresh->healer_meet_open)->toBeFalse();
});

it('grants amulet on buyer_meet if missing', function (): void {
    $player = cityArrived(88203, 'ScrBuyer');
    $amuletId = app(BuyerQuest::class)->amuletCatalogId();
    $module = app(Chapter1QuestModule::class);

    expect(backpack()->owns($player->tg_id, $amuletId))->toBeFalse();

    $module->applySnapshot($player, Chapter1ScreenEnum::BuyerMeet);
    $player->save();

    expect(backpack()->owns($player->tg_id, $amuletId))->toBeTrue()
        ->and($module->detect($player->fresh()))->toBe(Chapter1ScreenEnum::BuyerMeet);
});

it('grants purse on buyer_turn_in and keeps ready without purse', function (): void {
    $player = cityDone(88204, 'ScrPurse');
    $purseId = app(BuyerQuest::class)->purseCatalogId();
    $module = app(Chapter1QuestModule::class);

    $module->applySnapshot($player, Chapter1ScreenEnum::BruiserReadyForPurse);
    $player->save();

    expect(bag()->ownsQuestItem($player->tg_id, $purseId))->toBeFalse()
        ->and($module->detect($player->fresh()))->toBe(Chapter1ScreenEnum::BruiserReadyForPurse);

    $fresh = $player->fresh();
    $module->applySnapshot($fresh, Chapter1ScreenEnum::BuyerTurnIn);
    $fresh->save();

    expect(bag()->ownsQuestItem($fresh->tg_id, $purseId))->toBeTrue()
        ->and($fresh->bruiser_contract_active)->toBeFalse()
        ->and($module->detect($fresh->fresh()))->toBe(Chapter1ScreenEnum::BuyerTurnIn);
});

it('keeps quest_chapter chapter1 after chapter1_done', function (): void {
    $player = cityArrived(88205, 'ScrDone');
    app(Chapter1ScreenProjector::class)->apply($player, Chapter1ScreenEnum::Chapter1Done);
    $player->save();
    $fresh = $player->fresh();

    expect($fresh->quest_phase)->toBe(QuestPhaseEnum::Done)
        ->and($fresh->city_unlocked_full)->toBeTrue()
        ->and($fresh->quest_chapter)->toBe(QuestChapterEnum::Chapter1);
});

it('clears rat and knuckle after jump to overseer_gates', function (): void {
    $player = cityDone(88206, 'ScrClear');
    $player->healer_rat_loot_pending = true;
    $player->bruiser_knuckle_pending = true;
    $player->save();

    app(Chapter1ScreenProjector::class)->apply($player, Chapter1ScreenEnum::OverseerGates);
    $player->save();
    $fresh = $player->fresh();

    expect($fresh->healer_rat_loot_pending)->toBeFalse()
        ->and($fresh->bruiser_knuckle_pending)->toBeFalse()
        ->and(app(Chapter1QuestModule::class)->detect($fresh))->toBe(Chapter1ScreenEnum::OverseerGates);
});

it('projector alone does not strip amulet (revoke is reward sync)', function (): void {
    $player = cityArrived(88207, 'ScrKeep');
    $amuletId = app(BuyerQuest::class)->amuletCatalogId();
    $projector = app(Chapter1ScreenProjector::class);

    backpack()->addItem($player->tg_id, $amuletId);

    $projector->apply($player, Chapter1ScreenEnum::BuyerMeet);
    $player->save();

    expect(backpack()->owns($player->tg_id, $amuletId))->toBeTrue();

    $fresh = $player->fresh();
    $projector->apply($fresh, Chapter1ScreenEnum::OverseerGates);
    $fresh->save();

    expect(backpack()->owns($fresh->tg_id, $amuletId))->toBeTrue();
});

it('ownedStateKeys does not include ch2 keys', function (): void {
    $keys = app(Chapter1QuestModule::class)->ownedStateKeys();

    expect($keys)->not->toContain('ch2_phase')
        ->and($keys)->toContain('quest_phase')
        ->and($keys)->toContain('quest_chapter');
});

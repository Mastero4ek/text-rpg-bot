<?php

declare(strict_types=1);

use App\Actions\Character\CharacterApplyQuestScreenAction;
use App\Enums\ProgressStepEnum;
use App\Enums\Quest\QuestChapterEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Bag\BagItem;
use App\Models\Character;
use App\Models\Fight;
use App\Quests\BuyerQuest;
use App\Quests\Chapter1\Chapter1ScreenEnum;

it('applies healer_potion_lock and clears active fight', function (): void {
    $player = cityDone(88301, 'AsHealLock');
    fights()->createTraining($player, woodenSoldier($player));

    $result = app(CharacterApplyQuestScreenAction::class)->handle(
        $player,
        QuestChapterEnum::Chapter1,
        Chapter1ScreenEnum::HealerPotionLock->value,
    );

    expect($result->ok)->toBeTrue();

    $fresh = $player->fresh();

    expect($fresh->quest_phase)->toBe(QuestPhaseEnum::Healer)
        ->and($fresh->healer_meet_open)->toBeTrue()
        ->and(Fight::query()->whereKey($player->tg_id)->exists())->toBeFalse();
});

it('applies bruiser_arrive from chapter1_done', function (): void {
    $player = cityDone(88302, 'AsBruise');
    app(CharacterApplyQuestScreenAction::class)->handle(
        $player,
        QuestChapterEnum::Chapter1,
        Chapter1ScreenEnum::Chapter1Done->value,
    );

    $result = app(CharacterApplyQuestScreenAction::class)->handle(
        $player->fresh(),
        QuestChapterEnum::Chapter1,
        Chapter1ScreenEnum::BruiserArrive->value,
    );

    expect($result->ok)->toBeTrue();

    $fresh = $player->fresh();

    expect($fresh->quest_phase)->toBe(QuestPhaseEnum::Bruiser)
        ->and($fresh->bruiser_contract_active)->toBeTrue()
        ->and($fresh->city_unlocked_full)->toBeFalse();
});

it('applies registration_set_nick without city', function (): void {
    $player = characters()->createDraft(88303);
    $player->username = null;
    $player->city_id = null;
    $player->progress_step = ProgressStepEnum::SPLASH;
    $player->save();

    $result = app(CharacterApplyQuestScreenAction::class)->handle(
        $player,
        QuestChapterEnum::Chapter1,
        Chapter1ScreenEnum::RegistrationSetNick->value,
    );

    expect($result->ok)->toBeTrue()
        ->and($player->fresh()->progress_step)->toBe(ProgressStepEnum::SET_NICK);
});

it('fails on unknown screen value', function (): void {
    $player = cityArrived(88304, 'AsBadScr');

    $result = app(CharacterApplyQuestScreenAction::class)->handle(
        $player,
        QuestChapterEnum::Chapter1,
        'not_a_real_screen',
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe(__('admin.actions.apply_quest_screen.error.unknown_screen'));
});

it('rejects archived character', function (): void {
    $player = cityArrived(88305, 'AsArch');
    $player->delete();

    $archived = Character::withTrashed()->findOrFail(88305);

    $result = app(CharacterApplyQuestScreenAction::class)->handle(
        $archived,
        QuestChapterEnum::Chapter1,
        Chapter1ScreenEnum::HealerMeet->value,
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe(__('admin.actions.apply_quest_screen.error.archived'))
        ->and(Character::withTrashed()->findOrFail(88305)->quest_phase)->toBe(QuestPhaseEnum::Overseer);
});

it('rejects city screen without city', function (): void {
    $player = characters()->createDraft(88306);
    $player->username = 'AsNoCity';
    $player->city_id = null;
    $player->progress_step = ProgressStepEnum::SET_CITY;
    $player->save();

    $result = app(CharacterApplyQuestScreenAction::class)->handle(
        $player,
        QuestChapterEnum::Chapter1,
        Chapter1ScreenEnum::OverseerGates->value,
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe(__('admin.actions.apply_quest_screen.error.city_required'))
        ->and($player->fresh()->progress_step)->toBe(ProgressStepEnum::SET_CITY);
});

it('grants forward quest rewards and does not double on re-apply', function (): void {
    $player = cityArrived(88307, 'AsEco');
    $player->exp = 0;
    $player->silver = 0;
    $player->gold = 0;
    $player->save();

    $overseer = gameConfig()->quest()['rewards']['OverseerQuest'];

    app(CharacterApplyQuestScreenAction::class)->handle(
        $player,
        QuestChapterEnum::Chapter1,
        Chapter1ScreenEnum::HealerMeet->value,
    );

    $afterHealer = $player->fresh();

    expect($afterHealer->exp)->toBe($overseer['exp'])
        ->and($afterHealer->silver)->toBe($overseer['silver']);

    app(CharacterApplyQuestScreenAction::class)->handle(
        $afterHealer,
        QuestChapterEnum::Chapter1,
        Chapter1ScreenEnum::HealerMeet->value,
    );

    expect($player->fresh()->exp)->toBe($overseer['exp'])
        ->and($player->fresh()->silver)->toBe($overseer['silver']);
});

it('revokes rewards and quest items when jumping back', function (): void {
    $player = cityArrived(88309, 'AsBack');
    $amuletId = app(BuyerQuest::class)->amuletCatalogId();
    $purseId = app(BuyerQuest::class)->purseCatalogId();

    app(CharacterApplyQuestScreenAction::class)->handle(
        $player,
        QuestChapterEnum::Chapter1,
        Chapter1ScreenEnum::BuyerMeet->value,
    );

    app(CharacterApplyQuestScreenAction::class)->handle(
        $player->fresh(),
        QuestChapterEnum::Chapter1,
        Chapter1ScreenEnum::BuyerTurnIn->value,
    );

    $mid = $player->fresh();

    expect(backpack()->owns($mid->tg_id, $amuletId))->toBeFalse()
        ->and(bag()->ownsQuestItem($mid->tg_id, $purseId))->toBeTrue()
        ->and($mid->exp)->toBeGreaterThan(0);

    $expAfterForward = $mid->exp;

    app(CharacterApplyQuestScreenAction::class)->handle(
        $mid,
        QuestChapterEnum::Chapter1,
        Chapter1ScreenEnum::OverseerGates->value,
    );

    $back = $mid->fresh();

    expect($back->exp)->toBeLessThan($expAfterForward)
        ->and(backpack()->owns($back->tg_id, $amuletId))->toBeFalse()
        ->and(bag()->ownsQuestItem($back->tg_id, $purseId))->toBeFalse()
        ->and($back->quest_phase)->toBe(QuestPhaseEnum::Overseer);
});

it('grants cumulative rewards when jumping to chapter1_done', function (): void {
    $player = cityArrived(88308, 'AsDoneEco');
    $before = $player->fresh();

    $rewards = gameConfig()->quest()['rewards'];
    $deltaExp = $rewards['OverseerQuest']['exp']
        + $rewards['HealerQuest']['exp']
        + $rewards['BlacksmithQuest']['exp']
        + $rewards['BuyerQuest']['contract']['exp']
        + $rewards['BruiserQuest']['exp']
        + $rewards['BuyerQuest']['turnIn']['exp'];
    $deltaSilver = $rewards['OverseerQuest']['silver']
        + $rewards['HealerQuest']['silver']
        + $rewards['BlacksmithQuest']['silver']
        + $rewards['BuyerQuest']['contract']['silver']
        + $rewards['BruiserQuest']['silver']
        + $rewards['BuyerQuest']['turnIn']['silver'];
    $deltaGold = $rewards['BuyerQuest']['turnIn']['gold'];

    app(CharacterApplyQuestScreenAction::class)->handle(
        $before,
        QuestChapterEnum::Chapter1,
        Chapter1ScreenEnum::Chapter1Done->value,
    );

    $fresh = $before->fresh();
    $rewardsCfg = gameConfig()->quest()['rewards'];
    $potionIds = $rewardsCfg['HealerQuest']['potion_catalog_ids'];
    $mailId = $rewardsCfg['BlacksmithQuest']['mail_catalog_id'];
    $amuletId = $rewardsCfg['BlacksmithQuest']['amulet_catalog_id'];
    $knuckleId = $rewardsCfg['BruiserQuest']['knuckle_catalog_id'];
    $purseId = $rewardsCfg['BruiserQuest']['purse_catalog_id'];

    // silver may be higher: level-up thresholds also grant silverGain
    expect($fresh->exp)->toBe($before->exp + $deltaExp)
        ->and($fresh->silver)->toBeGreaterThanOrEqual($before->silver + $deltaSilver)
        ->and($fresh->gold)->toBe($before->gold + $deltaGold)
        ->and(backpack()->owns($fresh->tg_id, $mailId))->toBeTrue()
        ->and(backpack()->owns($fresh->tg_id, $amuletId))->toBeFalse()
        ->and(backpack()->owns($fresh->tg_id, $knuckleId))->toBeTrue()
        ->and(bag()->ownsQuestItem($fresh->tg_id, $purseId))->toBeFalse();

    foreach ($potionIds as $potionId) {
        expect(BagItem::query()
            ->where('tg_id', $fresh->tg_id)
            ->where('catalog_id', $potionId)
            ->whereNull('backpack_item_id')
            ->exists())->toBeTrue();
    }
});

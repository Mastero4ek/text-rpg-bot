<?php

declare(strict_types=1);

use App\Actions\Character\CharacterApplyQuestScreenAction;
use App\Enums\Quest\QuestChapterEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\Bag\BagItem;
use App\Models\LoadoutSlot;
use App\Quests\Chapter1\Chapter1ScreenEnum;

/**
 * @return array{
 *     potions: list<string>,
 *     mail: string,
 *     amulet: string,
 *     knuckle: string,
 *     purse: string,
 *     rat_tail: string
 * }
 */
function chapter1RewardIds(): array
{
    $rewards = gameConfig()->quest()['rewards'];

    return [
        'potions' => $rewards['HealerQuest']['potion_catalog_ids'],
        'mail' => $rewards['BlacksmithQuest']['mail_catalog_id'],
        'amulet' => $rewards['BlacksmithQuest']['amulet_catalog_id'],
        'knuckle' => $rewards['BruiserQuest']['knuckle_catalog_id'],
        'purse' => $rewards['BruiserQuest']['purse_catalog_id'],
        'rat_tail' => $rewards['HealerQuest']['debt']['rat_tail_catalog_id'],
    ];
}

function applyChapter1Screen(int $tgId, Chapter1ScreenEnum $screen): void
{
    $player = characters()->findByTgId($tgId);

    $result = app(CharacterApplyQuestScreenAction::class)->handle(
        $player,
        QuestChapterEnum::Chapter1,
        $screen->value,
    );

    expect($result->ok)->toBeTrue($screen->value);
}

function ownsLoosePotion(int $tgId, string $catalogId): bool
{
    return BagItem::query()
        ->where('tg_id', $tgId)
        ->where('catalog_id', $catalogId)
        ->whereNull('backpack_item_id')
        ->exists();
}

function mailIsEquipped(int $tgId, string $mailId): bool
{
    $item = BackpackItem::query()
        ->where('tg_id', $tgId)
        ->where('catalog_id', $mailId)
        ->first();

    if (! $item instanceof BackpackItem) {
        return false;
    }

    return LoadoutSlot::query()
        ->where('backpack_item_id', $item->id)
        ->exists();
}

it('does not grant quest items on healer_meet', function (): void {
    $player = cityArrived(88601, 'RwHeal');
    $ids = chapter1RewardIds();

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::HealerMeet);

    foreach ($ids['potions'] as $potionId) {
        expect(ownsLoosePotion($player->tg_id, $potionId))->toBeFalse();
    }

    expect(backpack()->owns($player->tg_id, $ids['mail']))->toBeFalse()
        ->and(backpack()->owns($player->tg_id, $ids['amulet']))->toBeFalse()
        ->and(backpack()->owns($player->tg_id, $ids['knuckle']))->toBeFalse()
        ->and(bag()->ownsQuestItem($player->tg_id, $ids['purse']))->toBeFalse();
});

it('grants healer potions on blacksmith_meet without gear', function (): void {
    $player = cityArrived(88602, 'RwSmith');
    $ids = chapter1RewardIds();

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::BlacksmithMeet);

    foreach ($ids['potions'] as $potionId) {
        expect(ownsLoosePotion($player->tg_id, $potionId))->toBeTrue();
    }

    expect(backpack()->owns($player->tg_id, $ids['mail']))->toBeFalse()
        ->and(backpack()->owns($player->tg_id, $ids['amulet']))->toBeFalse()
        ->and(backpack()->owns($player->tg_id, $ids['knuckle']))->toBeFalse()
        ->and(bag()->ownsQuestItem($player->tg_id, $ids['purse']))->toBeFalse();
});

it('grants mail amulet and potions on buyer_meet and equips mail', function (): void {
    $player = cityArrived(88603, 'RwBuyer');
    $ids = chapter1RewardIds();

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::BuyerMeet);

    foreach ($ids['potions'] as $potionId) {
        expect(ownsLoosePotion($player->tg_id, $potionId))->toBeTrue();
    }

    expect(backpack()->owns($player->tg_id, $ids['mail']))->toBeTrue()
        ->and(backpack()->owns($player->tg_id, $ids['amulet']))->toBeTrue()
        ->and(mailIsEquipped($player->tg_id, $ids['mail']))->toBeTrue()
        ->and(backpack()->owns($player->tg_id, $ids['knuckle']))->toBeFalse()
        ->and(bag()->ownsQuestItem($player->tg_id, $ids['purse']))->toBeFalse();
});

it('does not grant knuckle on bruiser_knuckle_pending and strips sold amulet', function (): void {
    $player = cityArrived(88604, 'RwKnPend');
    $ids = chapter1RewardIds();

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::BruiserKnucklePending);

    expect(backpack()->owns($player->tg_id, $ids['mail']))->toBeTrue()
        ->and(backpack()->owns($player->tg_id, $ids['amulet']))->toBeFalse()
        ->and(backpack()->owns($player->tg_id, $ids['knuckle']))->toBeFalse()
        ->and(bag()->ownsQuestItem($player->tg_id, $ids['purse']))->toBeFalse();
});

it('grants knuckle without purse or amulet on bruiser_ready_for_purse', function (): void {
    $player = cityArrived(88605, 'RwReady');
    $ids = chapter1RewardIds();

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::BruiserReadyForPurse);

    expect(backpack()->owns($player->tg_id, $ids['knuckle']))->toBeTrue()
        ->and(backpack()->owns($player->tg_id, $ids['amulet']))->toBeFalse()
        ->and(bag()->ownsQuestItem($player->tg_id, $ids['purse']))->toBeFalse();
});

it('grants purse and knuckle without amulet on buyer_turn_in', function (): void {
    $player = cityArrived(88606, 'RwTurn');
    $ids = chapter1RewardIds();

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::BuyerTurnIn);

    expect(backpack()->owns($player->tg_id, $ids['knuckle']))->toBeTrue()
        ->and(backpack()->owns($player->tg_id, $ids['amulet']))->toBeFalse()
        ->and(bag()->ownsQuestItem($player->tg_id, $ids['purse']))->toBeTrue();
});

it('grants full cumulative loot without sold amulet or purse on chapter1_done', function (): void {
    $player = cityArrived(88607, 'RwDone');
    $ids = chapter1RewardIds();

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::Chapter1Done);

    foreach ($ids['potions'] as $potionId) {
        expect(ownsLoosePotion($player->tg_id, $potionId))->toBeTrue();
    }

    expect(backpack()->owns($player->tg_id, $ids['mail']))->toBeTrue()
        ->and(backpack()->owns($player->tg_id, $ids['amulet']))->toBeFalse()
        ->and(backpack()->owns($player->tg_id, $ids['knuckle']))->toBeTrue()
        ->and(mailIsEquipped($player->tg_id, $ids['mail']))->toBeTrue()
        ->and(bag()->ownsQuestItem($player->tg_id, $ids['purse']))->toBeFalse();
});

it('does not duplicate items on re-apply of the same screen', function (): void {
    $player = cityArrived(88608, 'RwDup');
    $ids = chapter1RewardIds();

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::Chapter1Done);
    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::Chapter1Done);

    expect(BackpackItem::query()->where('tg_id', $player->tg_id)->where('catalog_id', $ids['mail'])->count())->toBe(1)
        ->and(BackpackItem::query()->where('tg_id', $player->tg_id)->where('catalog_id', $ids['amulet'])->count())->toBe(0)
        ->and(BackpackItem::query()->where('tg_id', $player->tg_id)->where('catalog_id', $ids['knuckle'])->count())->toBe(1);

    foreach ($ids['potions'] as $potionId) {
        expect(BagItem::query()
            ->where('tg_id', $player->tg_id)
            ->where('catalog_id', $potionId)
            ->whereNull('backpack_item_id')
            ->sum('quantity'))->toBe(1);
    }
});

it('strips purse when moving from buyer_turn_in to bruiser_ready_for_purse', function (): void {
    $player = cityArrived(88609, 'RwPurse');
    $ids = chapter1RewardIds();

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::BuyerTurnIn);
    expect(bag()->ownsQuestItem($player->tg_id, $ids['purse']))->toBeTrue();

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::BruiserReadyForPurse);

    expect(bag()->ownsQuestItem($player->tg_id, $ids['purse']))->toBeFalse()
        ->and(backpack()->owns($player->tg_id, $ids['knuckle']))->toBeTrue();
});

it('strips knuckle and purse and restores amulet when jumping back from done to buyer_meet', function (): void {
    $player = cityArrived(88610, 'RwBackBuy');
    $ids = chapter1RewardIds();

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::Chapter1Done);
    expect(backpack()->owns($player->tg_id, $ids['amulet']))->toBeFalse();
    $expDone = characters()->findByTgId($player->tg_id)->exp;

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::BuyerMeet);
    $back = characters()->findByTgId($player->tg_id);

    expect($back->exp)->toBeLessThan($expDone)
        ->and(backpack()->owns($back->tg_id, $ids['mail']))->toBeTrue()
        ->and(backpack()->owns($back->tg_id, $ids['amulet']))->toBeTrue()
        ->and(backpack()->owns($back->tg_id, $ids['knuckle']))->toBeFalse()
        ->and(bag()->ownsQuestItem($back->tg_id, $ids['purse']))->toBeFalse();

    foreach ($ids['potions'] as $potionId) {
        expect(ownsLoosePotion($back->tg_id, $potionId))->toBeTrue();
    }
});

it('strips amulet when jumping forward from buyer_meet to bruiser_arrive', function (): void {
    $player = cityArrived(88614, 'RwSellAmu');
    $ids = chapter1RewardIds();

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::BuyerMeet);
    expect(backpack()->owns($player->tg_id, $ids['amulet']))->toBeTrue();

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::BruiserArrive);

    expect(backpack()->owns($player->tg_id, $ids['amulet']))->toBeFalse()
        ->and(backpack()->owns($player->tg_id, $ids['mail']))->toBeTrue();
});

it('strips all quest rewards when jumping back to overseer_gates', function (): void {
    $player = cityArrived(88611, 'RwBackGate');
    $ids = chapter1RewardIds();

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::BuyerTurnIn);
    $expForward = characters()->findByTgId($player->tg_id)->exp;

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::OverseerGates);
    $back = characters()->findByTgId($player->tg_id);

    expect($back->exp)->toBeLessThan($expForward)
        ->and(backpack()->owns($back->tg_id, $ids['mail']))->toBeFalse()
        ->and(backpack()->owns($back->tg_id, $ids['amulet']))->toBeFalse()
        ->and(backpack()->owns($back->tg_id, $ids['knuckle']))->toBeFalse()
        ->and(bag()->ownsQuestItem($back->tg_id, $ids['purse']))->toBeFalse();

    foreach ($ids['potions'] as $potionId) {
        expect(ownsLoosePotion($back->tg_id, $potionId))->toBeFalse();
    }
});

it('restores purse when jumping from chapter1_done back to buyer_turn_in', function (): void {
    $player = cityArrived(88612, 'RwRestPurse');
    $ids = chapter1RewardIds();

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::Chapter1Done);
    expect(bag()->ownsQuestItem($player->tg_id, $ids['purse']))->toBeFalse();

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::BuyerTurnIn);

    expect(bag()->ownsQuestItem($player->tg_id, $ids['purse']))->toBeTrue()
        ->and(backpack()->owns($player->tg_id, $ids['knuckle']))->toBeTrue();
});

it('rejects unknown screen without mutating inventory', function (): void {
    $player = cityArrived(88613, 'RwBad');
    $ids = chapter1RewardIds();

    $result = app(CharacterApplyQuestScreenAction::class)->handle(
        $player,
        QuestChapterEnum::Chapter1,
        'not_a_real_screen',
    );

    expect($result->ok)->toBeFalse()
        ->and(backpack()->owns($player->tg_id, $ids['mail']))->toBeFalse()
        ->and(bag()->ownsQuestItem($player->tg_id, $ids['purse']))->toBeFalse();
});

it('strips all quest loot when jumping back to registration_splash', function (): void {
    $player = cityArrived(88617, 'RwRegBack');
    $ids = chapter1RewardIds();

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::Chapter1Done);
    expect(backpack()->owns($player->tg_id, $ids['knuckle']))->toBeTrue();

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::RegistrationSplash);

    expect(backpack()->owns($player->tg_id, $ids['mail']))->toBeFalse()
        ->and(backpack()->owns($player->tg_id, $ids['amulet']))->toBeFalse()
        ->and(backpack()->owns($player->tg_id, $ids['knuckle']))->toBeFalse()
        ->and(bag()->ownsQuestItem($player->tg_id, $ids['purse']))->toBeFalse();

    foreach ($ids['potions'] as $potionId) {
        expect(ownsLoosePotion($player->tg_id, $potionId))->toBeFalse();
    }
});

it('strips leftover rat_tail on any apply', function (): void {
    $player = cityArrived(88615, 'RwRat');
    $ids = chapter1RewardIds();

    bag()->addQuestItem($player->tg_id, $ids['rat_tail']);
    expect(bag()->ownsQuestItem($player->tg_id, $ids['rat_tail']))->toBeTrue();

    applyChapter1Screen($player->tg_id, Chapter1ScreenEnum::HealerRatLoot);

    expect(bag()->ownsQuestItem($player->tg_id, $ids['rat_tail']))->toBeFalse();
});

it('matches inventory matrix for every city screen', function (): void {
    $player = cityArrived(88616, 'RwMatrix');
    $ids = chapter1RewardIds();

    /** @var array<string, array{potions: bool, mail: bool, amulet: bool, knuckle: bool, purse: bool}> $matrix */
    $matrix = [
        Chapter1ScreenEnum::OverseerGates->value => ['potions' => false, 'mail' => false, 'amulet' => false, 'knuckle' => false, 'purse' => false],
        Chapter1ScreenEnum::HealerMeet->value => ['potions' => false, 'mail' => false, 'amulet' => false, 'knuckle' => false, 'purse' => false],
        Chapter1ScreenEnum::HealerPotionLock->value => ['potions' => false, 'mail' => false, 'amulet' => false, 'knuckle' => false, 'purse' => false],
        Chapter1ScreenEnum::BlacksmithMeet->value => ['potions' => true, 'mail' => false, 'amulet' => false, 'knuckle' => false, 'purse' => false],
        Chapter1ScreenEnum::BuyerMeet->value => ['potions' => true, 'mail' => true, 'amulet' => true, 'knuckle' => false, 'purse' => false],
        Chapter1ScreenEnum::BruiserArrive->value => ['potions' => true, 'mail' => true, 'amulet' => false, 'knuckle' => false, 'purse' => false],
        Chapter1ScreenEnum::HealerDebt->value => ['potions' => true, 'mail' => true, 'amulet' => false, 'knuckle' => false, 'purse' => false],
        Chapter1ScreenEnum::HealerRatLoot->value => ['potions' => true, 'mail' => true, 'amulet' => false, 'knuckle' => false, 'purse' => false],
        Chapter1ScreenEnum::BruiserKnucklePending->value => ['potions' => true, 'mail' => true, 'amulet' => false, 'knuckle' => false, 'purse' => false],
        Chapter1ScreenEnum::BruiserReadyForPurse->value => ['potions' => true, 'mail' => true, 'amulet' => false, 'knuckle' => true, 'purse' => false],
        Chapter1ScreenEnum::BuyerTurnIn->value => ['potions' => true, 'mail' => true, 'amulet' => false, 'knuckle' => true, 'purse' => true],
        Chapter1ScreenEnum::Chapter1Done->value => ['potions' => true, 'mail' => true, 'amulet' => false, 'knuckle' => true, 'purse' => false],
    ];

    foreach ($matrix as $screenValue => $expect) {
        $screen = Chapter1ScreenEnum::from($screenValue);
        applyChapter1Screen($player->tg_id, $screen);

        foreach ($ids['potions'] as $potionId) {
            expect(ownsLoosePotion($player->tg_id, $potionId))->toBe($expect['potions'], $screenValue . ' potions');
        }

        expect(backpack()->owns($player->tg_id, $ids['mail']))->toBe($expect['mail'], $screenValue . ' mail')
            ->and(backpack()->owns($player->tg_id, $ids['amulet']))->toBe($expect['amulet'], $screenValue . ' amulet')
            ->and(backpack()->owns($player->tg_id, $ids['knuckle']))->toBe($expect['knuckle'], $screenValue . ' knuckle')
            ->and(bag()->ownsQuestItem($player->tg_id, $ids['purse']))->toBe($expect['purse'], $screenValue . ' purse')
            ->and(bag()->ownsQuestItem($player->tg_id, $ids['rat_tail']))->toBeFalse($screenValue . ' rat_tail');
    }
});

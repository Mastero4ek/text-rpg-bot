<?php

declare(strict_types=1);

use App\Enums\ProgressStepEnum;
use App\Enums\StatKeyEnum;

it('tutorial fight persists and win lose', function (): void {
    $start = gameConfig()->character()['start'];
    $reward = gameConfig()->onboarding()['rewards']['tutorialQuest'];
    $p = registration()->ensurePlayer(3004);
    $p = registration()->setNick($p, 'Fighter')->character;
    $p = registration()->setLocation($p, registration()->cities()[0]->key)->character;

    $game = onboarding()->startTutorialFight($p);
    expect($game->tutorial)->toBeTrue()
        ->and(fights()->enemy($game)->level)->toBe(0)
        ->and($p->fresh()->progress_step)->toBe(ProgressStepEnum::TUTORIAL_FIGHT)
        ->and(fights()->exists($p->tg_id))->toBeTrue();

    $p->current_hp = 1;
    $p->save();
    $p = onboarding()->onTutorialLose($p);
    expect($p->current_hp)->toBe(characters()->maxHp($p));

    $p = onboarding()->onTutorialWin($p);
    expect($p->progress_step)->toBe(ProgressStepEnum::QUEST_STATS)
        ->and($p->silver)->toBe($start['silver'] + $reward['silver']);
});

it('stats equip shop free club full path', function (): void {
    $p = registration()->ensurePlayer(3005);
    $p = registration()->setNick($p, 'Graduate')->character;
    $p = registration()->setLocation($p, registration()->cities()[1]->key)->character;
    $p = onboarding()->onTutorialWin($p);

    expect(onboarding()->finishStatsQuest($p)->ok)->toBeFalse();

    while ($p->stat_points > 0) {
        $p = characters()->spendStatPoint($p, StatKeyEnum::STRENGTH->value)->character;
    }

    $res = onboarding()->finishStatsQuest($p);
    expect($res->ok)->toBeTrue();
    $p = $res->character;
    expect($p->progress_step)->toBe(ProgressStepEnum::QUEST_EQUIP);

    $res = onboarding()->finishEquipQuest($p);
    expect($res->ok)->toBeTrue();
    $p = $res->character;
    $mail = backpack()->findOwned($p->tg_id, shopCatalog()->mailShirtId());
    expect($mail->isEquipped())->toBeTrue()
        ->and($mail->slot)->toBe(App\Enums\Equipment\SlotEnum::ARMOR)
        ->and($p->progress_step)->toBe(ProgressStepEnum::QUEST_SHOP);

    $levelBeforeShop = $p->level;
    $res = onboarding()->finishShopQuestClaim($p, shopCatalog()->freeTrainerItemId());
    expect($res->ok)->toBeTrue();
    $p = $res->character;
    $weapon = backpack()->findOwned($p->tg_id, shopCatalog()->freeTrainerItemId());
    expect($p->progress_step)->toBe(ProgressStepEnum::DONE)
        ->and($p->level)->toBeGreaterThanOrEqual($levelBeforeShop)
        ->and(hasLooseGem($p, 'ruby_0'))->toBeFalse()
        ->and($weapon->isEquipped())->toBeTrue()
        ->and($weapon->slot)->toBe(App\Enums\Equipment\SlotEnum::RIGHT_HAND);
});

it('graduates from each seed city via trainer club', function (string $cityKey): void {
    $p = registration()->ensurePlayer(match ($cityKey) {
        App\Models\City::KEY_ANKRAT => 3010,
        App\Models\City::KEY_ELDWOOD => 3011,
        default => 3012,
    });
    $p = registration()->setNick($p, 'Grad' . $cityKey)->character;
    $p = registration()->setLocation($p, $cityKey)->character;
    $p = onboarding()->onTutorialWin($p);

    while ($p->stat_points > 0) {
        $p = characters()->spendStatPoint($p, StatKeyEnum::STRENGTH->value)->character;
    }

    $p = onboarding()->finishStatsQuest($p)->character;
    $p = onboarding()->finishEquipQuest($p)->character;

    $res = onboarding()->finishShopQuestClaim($p, shopCatalog()->freeTrainerItemId());

    expect($res->ok)->toBeTrue()
        ->and($res->character->progress_step)->toBe(ProgressStepEnum::DONE)
        ->and($res->character->city_id)->toBe(App\Models\City::query()->where('key', $cityKey)->value('id'))
        ->and($res->character->birth_city_id)->toBe($res->character->city_id);
})->with([
    App\Models\City::KEY_ANKRAT,
    App\Models\City::KEY_ELDWOOD,
    App\Models\City::KEY_THORNBREAK,
]);

it('stepHint covers known onboarding steps', function (): void {
    expect(onboarding()->stepHint(ProgressStepEnum::INTRO->value))->not->toBeEmpty()
        ->and(onboarding()->stepHint(ProgressStepEnum::TUTORIAL_FIGHT->value))->not->toBeEmpty()
        ->and(onboarding()->stepHint('unknown_step'))->not->toBeEmpty();
});

it('finishShopQuestClaim rejects non trainer weapon', function (): void {
    $p = registration()->ensurePlayer(3006);
    $p = registration()->setNick($p, 'ShopBad')->character;
    $p = registration()->setLocation($p, registration()->cities()[0]->key)->character;
    $p = onboarding()->onTutorialWin($p);

    while ($p->stat_points > 0) {
        $p = characters()->spendStatPoint($p, StatKeyEnum::STRENGTH->value)->character;
    }

    $p = onboarding()->finishStatsQuest($p)->character;
    $p = onboarding()->finishEquipQuest($p)->character;

    expect(onboarding()->finishShopQuestClaim($p, 'knife_0')->ok)->toBeFalse();
});

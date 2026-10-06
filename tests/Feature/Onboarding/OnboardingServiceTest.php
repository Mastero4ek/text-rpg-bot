<?php

declare(strict_types=1);

use App\Enums\OnboardingStepEnum;
use App\Enums\StatKeyEnum;

it('ensurePlayer creates once', function (): void {
    $a = onboarding()->ensurePlayer(3001);
    $b = onboarding()->ensurePlayer(3001);

    expect($a->tg_id)->toBe($b->tg_id)
        ->and($a->onboarding_step)->toBe(OnboardingStepEnum::NICK);
});

it('setNick validates and uniqueness', function (): void {
    $p1 = onboarding()->ensurePlayer(3002);

    expect(onboarding()->setNick($p1, 'ab')->ok)->toBeFalse();

    $ok = onboarding()->setNick($p1, 'HeroOne');
    expect($ok->ok)->toBeTrue()
        ->and($ok->character->onboarding_step)->toBe(OnboardingStepEnum::CITY);

    $p2 = onboarding()->ensurePlayer(3052);
    expect(onboarding()->setNick($p2, 'HeroOne')->ok)->toBeFalse();
});

it('setLocation only from list', function (): void {
    $p = onboarding()->ensurePlayer(3003);
    $p = onboarding()->setNick($p, 'CityGuy')->character;

    expect(onboarding()->setLocation($p, 'Nowhere')->ok)->toBeFalse();

    $hidden = App\Models\City::factory()->create([
        'name' => 'Скрытый',
        'enabled' => false,
    ]);
    expect(onboarding()->setLocation($p, $hidden->key)->ok)->toBeFalse();

    $city = onboarding()->cities()[0];
    $res = onboarding()->setLocation($p, $city->key);

    expect($res->ok)->toBeTrue()
        ->and($res->character->city_id)->toBe($city->id)
        ->and($res->character->birth_city_id)->toBe($city->id)
        ->and($res->character->onboarding_step)->toBe(OnboardingStepEnum::INTRO);
});

it('tutorial fight persists and win lose', function (): void {
    $ob = gameConfig()->onboarding();
    $p = onboarding()->ensurePlayer(3004);
    $p = onboarding()->setNick($p, 'Fighter')->character;
    $p = onboarding()->setLocation($p, onboarding()->cities()[0]->key)->character;

    $game = onboarding()->startTutorialFight($p);
    expect($game->tutorial)->toBeTrue()
        ->and(fights()->enemy($game)->level)->toBe(0)
        ->and($p->fresh()->onboarding_step)->toBe(OnboardingStepEnum::TUTORIAL_FIGHT)
        ->and(fights()->exists($p->tg_id))->toBeTrue();

    $p->current_hp = 1;
    $p->save();
    $p = onboarding()->onTutorialLose($p);
    expect($p->current_hp)->toBe(characters()->maxHp($p));

    $p = onboarding()->onTutorialWin($p);
    expect($p->onboarding_step)->toBe(OnboardingStepEnum::QUEST_STATS)
        ->and($p->silver)->toBe($ob['start']['silver'] + $ob['rewards']['tutorialWin']['silver']);
});

it('stats equip shop free club full path', function (): void {
    $ob = gameConfig()->onboarding();
    $p = onboarding()->ensurePlayer(3005);
    $p = onboarding()->setNick($p, 'Graduate')->character;
    $p = onboarding()->setLocation($p, onboarding()->cities()[1]->key)->character;
    $p = onboarding()->onTutorialWin($p);

    expect(onboarding()->finishStatsQuest($p)->ok)->toBeFalse();

    while ($p->stat_points > 0) {
        $p = characters()->spendStatPoint($p, StatKeyEnum::STRENGTH->value)->character;
    }

    $res = onboarding()->finishStatsQuest($p);
    expect($res->ok)->toBeTrue();
    $p = $res->character;
    expect($p->onboarding_step)->toBe(OnboardingStepEnum::QUEST_EQUIP);

    $res = onboarding()->finishEquipQuest($p);
    expect($res->ok)->toBeTrue();
    $p = $res->character;
    $mail = backpack()->findOwned($p->tg_id, shopCatalog()->mailShirtId());
    expect($mail->isEquipped())->toBeTrue()
        ->and($mail->slot)->toBe(App\Enums\Equipment\SlotEnum::ARMOR)
        ->and($p->onboarding_step)->toBe(OnboardingStepEnum::QUEST_SHOP);

    $res = onboarding()->finishShopQuestClaim($p, shopCatalog()->freeTrainerItemId());
    expect($res->ok)->toBeTrue();
    $p = $res->character;
    $weapon = backpack()->findOwned($p->tg_id, shopCatalog()->freeTrainerItemId());
    expect($p->onboarding_step)->toBe(OnboardingStepEnum::DONE)
        ->and($p->level)->toBe($ob['graduateLevel'])
        ->and($weapon->isEquipped())->toBeTrue()
        ->and($weapon->slot)->toBe(App\Enums\Equipment\SlotEnum::RIGHT_HAND);
});

it('graduates from each seed city via trainer club', function (string $cityKey): void {
    $p = onboarding()->ensurePlayer(match ($cityKey) {
        App\Models\City::KEY_YASEN => 3010,
        App\Models\City::KEY_KURGAN => 3011,
        default => 3012,
    });
    $p = onboarding()->setNick($p, 'Grad' . $cityKey)->character;
    $p = onboarding()->setLocation($p, $cityKey)->character;
    $p = onboarding()->onTutorialWin($p);

    while ($p->stat_points > 0) {
        $p = characters()->spendStatPoint($p, StatKeyEnum::STRENGTH->value)->character;
    }

    $p = onboarding()->finishStatsQuest($p)->character;
    $p = onboarding()->finishEquipQuest($p)->character;

    $res = onboarding()->finishShopQuestClaim($p, shopCatalog()->freeTrainerItemId());

    expect($res->ok)->toBeTrue()
        ->and($res->character->onboarding_step)->toBe(OnboardingStepEnum::DONE)
        ->and($res->character->city_id)->toBe(App\Models\City::query()->where('key', $cityKey)->value('id'))
        ->and($res->character->birth_city_id)->toBe($res->character->city_id);
})->with([
    App\Models\City::KEY_YASEN,
    App\Models\City::KEY_KURGAN,
    App\Models\City::KEY_LIMAN,
]);

it('stepHint covers known steps', function (): void {
    expect(onboarding()->stepHint(OnboardingStepEnum::NICK->value))->not->toBeEmpty()
        ->and(onboarding()->stepHint('unknown_step'))->not->toBeEmpty();
});

it('finishShopQuestClaim rejects non trainer weapon', function (): void {
    $p = onboarding()->ensurePlayer(3006);
    $p = onboarding()->setNick($p, 'ShopBad')->character;
    $p = onboarding()->setLocation($p, onboarding()->cities()[0]->key)->character;
    $p = onboarding()->onTutorialWin($p);

    while ($p->stat_points > 0) {
        $p = characters()->spendStatPoint($p, StatKeyEnum::STRENGTH->value)->character;
    }

    $p = onboarding()->finishStatsQuest($p)->character;
    $p = onboarding()->finishEquipQuest($p)->character;

    expect(onboarding()->finishShopQuestClaim($p, 'knife_0')->ok)->toBeFalse();
});

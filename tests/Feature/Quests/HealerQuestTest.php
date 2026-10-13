<?php

declare(strict_types=1);

use App\Actions\Healer\HealerGivePotionAction;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\City;
use App\Quests\HealerQuest;
use App\Support\Telegram\TelegramHtml;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('shows healer panel on ruins during healer phase', function (): void {
    $player = cityHealerPhase(98101, 'HealerMeet');
    $city = City::query()->findOrFail($player->city_id);

    expect(app(HealerQuest::class)->awaitsPotion($player))->toBeTrue();

    cityFlowCallback($player->tg_id, 9, 'city:ruins');

    assertCityEditHas(TelegramHtml::escape($city->name));
    assertCityEditMarkupHas('quest:healer:take');
    assertCityEditMarkupHas(__('telegram.quest.healer.btn.take'));

    Http::assertNotSent(function ($request): bool {
        $body = (string) $request->body();

        return str_contains($body, 'city:ruins:list:')
            || str_contains($body, __('telegram.location.ruins'));
    });
});

it('gives heal and stamina potions with rewards and unlocks forest', function (): void {
    $player = cityHealerPhase(98102, 'HealerTake');
    $hpBefore = $player->current_hp;

    $res = app(HealerGivePotionAction::class)->handle($player);

    expect($res->ok)->toBeTrue();

    $player->refresh();
    expect($player->exp)->toBe(70)
        ->and($player->stat_points)->toBe(1)
        ->and($player->healer_at_tavern)->toBeTrue()
        ->and($player->unlock_forest)->toBeTrue()
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Blacksmith)
        ->and($player->current_hp)->toBe($hpBefore)
        ->and(bag()->potionCountByProfile($player->tg_id, ProfileEnum::HEAL))->toBe(1)
        ->and(bag()->potionCountByProfile($player->tg_id, ProfileEnum::STAMINA))->toBe(1)
        ->and(app(HealerQuest::class)->awaitsPotion($player))->toBeFalse();
});

it('keeps portal fog after healer complete until quest chain finale', function (): void {
    $player = cityHealerPhase(98103, 'HealerTravel');
    $player->loadMissing('city');
    $cityEscaped = TelegramHtml::escape($player->city->name);
    expect(app(HealerGivePotionAction::class)->handle($player)->ok)->toBeTrue();

    cityFlowCallback($player->tg_id, 9, 'city:portal');

    expect($player->fresh()->unlock_portal)->toBeFalse();
    assertCityEditHas($cityEscaped);
    assertCityEditMarkupHas('city:gates');
    Http::assertNotSent(function ($request): bool {
        $body = (string) $request->body();

        return str_contains($body, 'quest:healer:take')
            || str_contains($body, 'portal:list:')
            || str_contains($body, __('telegram.location.portal_title'))
            || str_contains($body, __('telegram.location.portal_locked'));
    });
});

it('rejects double take and wrong phase', function (): void {
    $player = cityHealerPhase(98104, 'HealerTwice');
    expect(app(HealerGivePotionAction::class)->handle($player)->ok)->toBeTrue();

    $again = app(HealerGivePotionAction::class)->handle($player->fresh());
    expect($again->ok)->toBeFalse()
        ->and(bag()->potionCountByProfile($player->tg_id, ProfileEnum::HEAL))->toBe(1)
        ->and(bag()->potionCountByProfile($player->tg_id, ProfileEnum::STAMINA))->toBe(1);

    $wrong = cityArrived(98105, 'HealerWrong');
    expect(app(HealerGivePotionAction::class)->handle($wrong)->ok)->toBeFalse()
        ->and(bag()->potionCountByProfile($wrong->tg_id, ProfileEnum::HEAL))->toBe(0)
        ->and(bag()->potionCountByProfile($wrong->tg_id, ProfileEnum::STAMINA))->toBe(0);
});

it('keeps fog on ruins without unlock', function (): void {
    $player = cityArrivedSkipped(98106, 'HealerFog');
    $player->loadMissing('city');
    $cityEscaped = TelegramHtml::escape($player->city->name);

    cityFlowCallback($player->tg_id, 9, 'city:ruins');

    expect($player->fresh()->unlock_ruins)->toBeFalse();
    assertCityEditHas($cityEscaped);
});

it('fails when bag is full without advancing phase', function (): void {
    $player = cityHealerPhase(98107, 'HealerFull');
    $player->bag_max_rows = 1;
    $player->save();
    bag()->grantGem($player, 'ruby_0', 1);

    $res = app(HealerGivePotionAction::class)->handle($player->fresh());

    expect($res->ok)->toBeFalse()
        ->and($res->error)->toBe(__('telegram.quest.healer.error.bag_full'));

    $player->refresh();
    expect($player->quest_phase)->toBe(QuestPhaseEnum::Healer)
        ->and($player->healer_at_tavern)->toBeFalse()
        ->and($player->unlock_forest)->toBeFalse()
        ->and($player->exp)->toBe(30)
        ->and(bag()->potionCountByProfile($player->tg_id, ProfileEnum::HEAL))->toBe(0)
        ->and(bag()->potionCountByProfile($player->tg_id, ProfileEnum::STAMINA))->toBe(0);
});

it('keeps potions in bag after telegram take without consume', function (): void {
    $player = cityHealerPhase(98108, 'HealerTg');

    cityFlowCallback($player->tg_id, 9, 'quest:healer:take');

    $player->refresh();
    expect($player->exp)->toBe(70)
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Blacksmith)
        ->and(bag()->potionCountByProfile($player->tg_id, ProfileEnum::HEAL))->toBe(1)
        ->and(bag()->potionCountByProfile($player->tg_id, ProfileEnum::STAMINA))->toBe(1);

    assertCityEditHas(mb_trim(__('telegram.quest.healer.complete')));
    assertCityEditMarkupHas('city:gates');
    assertCityEditMarkupHas(__('telegram.quest.healer.btn.to_city'));
});

it('opens city hub before visiting healer ruins', function (): void {
    $player = cityHealerPhase(98109, 'HealerHub');

    cityFlowCallback($player->tg_id, 9, 'city:home');

    assertCityEditMarkupHas('city:gates');
    assertCityEditMarkupHas('city:tavern');
    expect($player->fresh()->healer_meet_open)->toBeFalse()
        ->and($player->fresh()->quest_phase)->toBe(QuestPhaseEnum::Healer);

    Http::assertNotSent(function ($request): bool {
        return str_contains((string) $request->body(), 'quest:healer:take');
    });
});

it('soft-locks city callbacks after healer ruins until potion is taken', function (): void {
    $player = cityHealerPhase(98110, 'HealerLock');

    cityFlowCallback($player->tg_id, 9, 'city:ruins');
    expect($player->fresh()->healer_meet_open)->toBeTrue();

    cityFlowCallback($player->tg_id, 9, 'city:home');

    assertCityEditMarkupHas('quest:healer:take');
    expect($player->fresh()->quest_phase)->toBe(QuestPhaseEnum::Healer);
});

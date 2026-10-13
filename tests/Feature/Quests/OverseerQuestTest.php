<?php

declare(strict_types=1);

use App\Actions\Overseer\OverseerAcceptChallengeAction;
use App\Actions\Overseer\OverseerAgreeTrainingAction;
use App\Actions\Overseer\OverseerCompleteAction;
use App\Actions\Overseer\OverseerSkipGatesAction;
use App\Actions\Overseer\OverseerSubmitStatsAction;
use App\Enums\ProgressStepEnum;
use App\Enums\Quest\OverseerTrainStepEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Enums\StatKeyEnum;
use App\Models\City;
use App\Quests\OverseerQuest;
use App\Support\Telegram\TelegramHtml;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('shows meet screen after registration with city placeholder', function (): void {
    $player = cityArrived(98001, 'MeetHero');
    $city = City::query()->findOrFail($player->city_id);

    expect($player->progress_step)->toBe(ProgressStepEnum::ARRIVED)
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Overseer)
        ->and(app(OverseerQuest::class)->awaitsMeet($player))->toBeTrue();

    cityFlowCallback($player->tg_id, 9, 'city:home');

    assertCityEditHas(TelegramHtml::escape($city->name));
    assertCityEditMarkupHas('quest:overseer:accept');
    assertCityEditMarkupHas('quest:overseer:skip');
});

it('completes accept train pipeline with rewards and portal unlock', function (): void {
    $player = cityArrived(98002, 'TrainHero');

    expect(app(OverseerAcceptChallengeAction::class)->handle($player)->ok)->toBeTrue();
    $player->refresh();
    expect($player->overseer_train_step)->toBe(OverseerTrainStepEnum::Intro)
        ->and(app(OverseerQuest::class)->isTrainLocked($player))->toBeTrue();

    expect(app(OverseerQuest::class)->startFight($player)->ok)->toBeTrue();
    $player->refresh();
    expect($player->overseer_train_step)->toBe(OverseerTrainStepEnum::Fight)
        ->and($player->exp)->toBe(0);

    expect(app(OverseerQuest::class)->advanceAfterWin($player)->ok)->toBeTrue();
    $player->refresh();
    expect($player->overseer_train_step)->toBe(OverseerTrainStepEnum::Stats)
        ->and($player->exp)->toBe(0);

    foreach ([StatKeyEnum::STRENGTH, StatKeyEnum::AGILITY, StatKeyEnum::INSTINCT] as $stat) {
        expect(characters()->spendStatPoint($player->fresh(), $stat->value)->ok)->toBeTrue();
    }

    $player->refresh();
    expect($player->stat_points)->toBe(0);
    expect(app(OverseerQuest::class)->completeAfterStats($player)->ok)->toBeTrue();

    $player->refresh();
    expect($player->exp)->toBe(30)
        ->and($player->silver)->toBe(25)
        ->and($player->stat_points)->toBe(0)
        ->and($player->unlock_ruins)->toBeTrue()
        ->and($player->unlock_portal)->toBeFalse()
        ->and($player->overseer_trained)->toBeTrue()
        ->and($player->overseer_at_tavern)->toBeTrue()
        ->and($player->overseer_train_step)->toBeNull()
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Healer)
        ->and($player->progress_step)->toBe(ProgressStepEnum::DONE)
        ->and($player->current_hp)->toBe(characters()->maxHp($player))
        ->and($player->current_stamina)->toBe(characters()->maxStamina($player));
});

it('skip opens hub then tavern train reaches same complete', function (): void {
    $player = cityArrived(98003, 'SkipHero');

    expect(app(OverseerSkipGatesAction::class)->handle($player)->ok)->toBeTrue();
    $player->refresh();
    expect($player->overseer_at_tavern)->toBeTrue()
        ->and($player->exp)->toBe(0)
        ->and($player->unlock_ruins)->toBeFalse()
        ->and($player->unlock_portal)->toBeFalse()
        ->and(app(OverseerQuest::class)->awaitsMeet($player))->toBeFalse();

    cityFlowCallback($player->tg_id, 9, 'city:home');
    assertCityEditMarkupHas('city:tavern');

    expect(app(OverseerAgreeTrainingAction::class)->handle($player->fresh())->ok)->toBeTrue();
    $player->refresh();
    expect($player->overseer_train_step)->toBe(OverseerTrainStepEnum::Intro);

    app(OverseerQuest::class)->startFight($player->fresh());
    app(OverseerQuest::class)->advanceAfterWin($player->fresh());

    foreach ([StatKeyEnum::STRENGTH, StatKeyEnum::AGILITY, StatKeyEnum::INSTINCT] as $stat) {
        characters()->spendStatPoint($player->fresh(), $stat->value);
    }

    expect(app(OverseerQuest::class)->completeAfterStats($player->fresh())->ok)->toBeTrue();
    $player->refresh();
    expect($player->exp)->toBe(30)
        ->and($player->stat_points)->toBe(0)
        ->and($player->unlock_ruins)->toBeTrue()
        ->and($player->unlock_portal)->toBeFalse()
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Healer);
});

it('rejects submit stats while points remain', function (): void {
    $player = cityArrived(98004, 'StatsFail');
    app(OverseerAcceptChallengeAction::class)->handle($player);
    app(OverseerQuest::class)->startFight($player->fresh());
    app(OverseerQuest::class)->advanceAfterWin($player->fresh());

    $res = app(OverseerSubmitStatsAction::class)->handle($player->fresh());
    expect($res->ok)->toBeFalse();

    $player->refresh();
    expect($player->quest_phase)->toBe(QuestPhaseEnum::Overseer)
        ->and($player->overseer_train_step)->toBe(OverseerTrainStepEnum::Stats);
});

it('rejects overseer callbacks after complete', function (): void {
    $player = cityArrived(98005, 'DoneHero');
    app(OverseerAcceptChallengeAction::class)->handle($player);
    app(OverseerQuest::class)->startFight($player->fresh());
    app(OverseerQuest::class)->advanceAfterWin($player->fresh());

    foreach ([StatKeyEnum::STRENGTH, StatKeyEnum::AGILITY, StatKeyEnum::INSTINCT] as $stat) {
        characters()->spendStatPoint($player->fresh(), $stat->value);
    }

    app(OverseerCompleteAction::class)->handle($player->fresh());

    expect(app(OverseerAcceptChallengeAction::class)->handle($player->fresh())->ok)->toBeFalse()
        ->and(app(OverseerSkipGatesAction::class)->handle($player->fresh())->ok)->toBeFalse()
        ->and(app(OverseerAgreeTrainingAction::class)->handle($player->fresh())->ok)->toBeFalse();
});

it('keeps fog on portal and ruins before complete', function (): void {
    $player = cityArrivedSkipped(98006, 'FogHero');
    $player->loadMissing('city');
    $cityEscaped = TelegramHtml::escape($player->city->name);

    cityFlowCallback($player->tg_id, 9, 'city:portal');

    expect($player->fresh()->unlock_portal)->toBeFalse()
        ->and($player->fresh()->unlock_ruins)->toBeFalse();
    assertCityEditHas($cityEscaped);

    cityFlowCallback($player->tg_id, 10, 'city:ruins');
    assertCityEditHas($cityEscaped);
});

it('auto-completes overseer quest on last telegram stat spend', function (): void {
    $player = cityArrived(98007, 'AutoComplete');
    app(OverseerAcceptChallengeAction::class)->handle($player);
    app(OverseerQuest::class)->startFight($player->fresh());
    app(OverseerQuest::class)->advanceAfterWin($player->fresh());

    $player->refresh();
    $player->stat_points = 1;
    $player->tg_chat_id = $player->tg_id;
    $player->tg_message_id = 9;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'quest:overseer:stat:' . StatKeyEnum::STRENGTH->value);

    $player->refresh();
    expect($player->stat_points)->toBe(0)
        ->and($player->exp)->toBe(30)
        ->and($player->overseer_trained)->toBeTrue()
        ->and($player->unlock_ruins)->toBeTrue()
        ->and($player->unlock_portal)->toBeFalse()
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Healer)
        ->and($player->overseer_train_step)->toBeNull();

    assertCityEditHas(mb_trim(__('telegram.quest.overseer.complete')));
    assertCityEditMarkupHas('city:home');
    assertCityEditMarkupHas(__('telegram.quest.overseer.btn.to_city'));

    Http::assertNotSent(function ($request): bool {
        return str_contains($request->body(), 'quest:overseer:submit');
    });
});

it('keeps stats panel after non-final telegram stat spend', function (): void {
    $player = cityArrived(98008, 'StatsMid');
    app(OverseerAcceptChallengeAction::class)->handle($player);
    app(OverseerQuest::class)->startFight($player->fresh());
    app(OverseerQuest::class)->advanceAfterWin($player->fresh());

    $player->refresh();
    $player->stat_points = 2;
    $player->tg_chat_id = $player->tg_id;
    $player->tg_message_id = 9;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'quest:overseer:stat:' . StatKeyEnum::AGILITY->value);

    $player->refresh();
    expect($player->stat_points)->toBe(1)
        ->and($player->overseer_trained)->toBeFalse()
        ->and($player->overseer_train_step)->toBe(OverseerTrainStepEnum::Stats)
        ->and($player->exp)->toBe(0);

    assertCityEditHas(mb_trim(__('telegram.quest.overseer.stats', [
        'points' => 1,
        'str' => $player->strength,
        'agi' => $player->agility,
        'inst' => $player->instinct,
        'vit' => $player->vitality,
    ])));
    assertCityEditMarkupHas('quest:overseer:stat:' . StatKeyEnum::STRENGTH->value);

    Http::assertNotSent(function ($request): bool {
        $body = $request->body();

        return str_contains($body, 'city:home')
            || str_contains($body, 'quest:overseer:submit');
    });
});

it('resets training to intro after tutorial lose without quest exp', function (): void {
    $player = cityArrived(98009, 'LoseRetry');
    app(OverseerAcceptChallengeAction::class)->handle($player);
    app(OverseerQuest::class)->startFight($player->fresh());

    $player->refresh();
    $player->current_hp = 1;
    $player->save();

    $res = app(OverseerQuest::class)->resetAfterLose($player->fresh());

    expect($res->ok)->toBeTrue();

    $player->refresh();
    expect($player->overseer_train_step)->toBe(OverseerTrainStepEnum::Intro)
        ->and($player->exp)->toBe(0)
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Overseer)
        ->and($player->unlock_portal)->toBeFalse()
        ->and($player->unlock_ruins)->toBeFalse()
        ->and($player->current_hp)->toBe(characters()->maxHp($player));
});

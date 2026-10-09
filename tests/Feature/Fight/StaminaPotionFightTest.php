<?php

declare(strict_types=1);

use App\Enums\Combat\StanceEnum;
use App\Enums\Combat\ZoneEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\Fight\PlayerAttackEnum;
use App\Enums\ProgressStepEnum;
use App\Jobs\ResolveFightTurnTimeoutJob;
use App\Models\Fight;
use App\Services\Fight\FightRoundService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

it('drinks stamina potion instead of attacking and clamps to max', function (): void {
    Bus::fake();

    $p = giveAndEquipStarterKnuckles(characters()->createDraft(1401));
    $p->username = 'PotionStamina';
    $p->silver = bagCatalog()->staminaPotionPrice();
    $p = placeInCity($p, App\Models\City::KEY_ANKRAT);
    buyerService()->buyStaminaPotion($p->tg_id);

    $heal = bagCatalog()->potionStaminaHeal();
    expect($heal)->toBeInt()
        ->and(bag()->potionCountByProfile($p->tg_id, ProfileEnum::STAMINA))->toBe(1);

    $fight = fights()->createTraining($p->fresh(), woodenSoldier($p));
    $enemy = $fight->enemy;
    $enemy['current_hp'] = 500;
    $enemy['maxHp'] = 500;
    $fight->enemy = $enemy;
    $fight->player_hp = 500;
    $fight->player_max_hp = 500;
    $fight->player_stamina = 10;
    $fight->player_max_stamina = 60;
    $fight->player_stance = StanceEnum::DEFEND;
    $fight->player_attack = PlayerAttackEnum::STAMINA_POTION;
    $fight->use_potion = true;
    $fight->player_defend = ZoneEnum::CHEST;
    $fight->step = FightStepEnum::DEFEND;
    $fight->save();

    // enemy attack: stance already set on enemy via AI rolls — dodge miss, crit miss, variance
    fakeRandom([0.99, 0.0, 0.75, 0.99, 0.99, 0.5]);

    $outcome = app(FightRoundService::class)->runRound($p->fresh());
    $fight->refresh();
    $drain = gameConfig()->combat()['stamina']['drainDefend'];

    expect($outcome->kind)->toBe('continue')
        ->and($fight->player_stamina)->toBe(10 + $heal - $drain)
        ->and(bag()->potionCountByProfile($p->tg_id, ProfileEnum::STAMINA))->toBe(0)
        ->and(implode("\n", $fight->log))->toContain((string) $heal);
});

it('drinks heal potion instead of attacking', function (): void {
    Bus::fake();

    $p = giveAndEquipStarterKnuckles(characters()->createDraft(1402));
    $p->username = 'PotionHeal';
    $p->silver = bagCatalog()->potionPrice();
    $p = placeInCity($p, App\Models\City::KEY_ANKRAT);
    buyerService()->buyPotion($p->tg_id);

    $heal = bagCatalog()->potionHeal();

    $fight = fights()->createTraining($p->fresh(), woodenSoldier($p));
    $enemy = $fight->enemy;
    $enemy['current_hp'] = 500;
    $enemy['maxHp'] = 500;
    $fight->enemy = $enemy;
    $fight->player_hp = 20;
    $fight->player_max_hp = 200;
    $fight->player_stamina = 60;
    $fight->player_max_stamina = 60;
    $fight->player_stance = StanceEnum::DEFEND;
    $fight->player_attack = PlayerAttackEnum::POTION;
    $fight->use_potion = true;
    $fight->player_defend = ZoneEnum::CHEST;
    $fight->step = FightStepEnum::DEFEND;
    $fight->save();

    fakeRandom([0.99, 0.0, 0.75, 0.99, 0.99, 0.5]);

    app(FightRoundService::class)->runRound($p->fresh());
    $fight->refresh();

    expect($fight->player_hp)->toBeGreaterThan(20)
        ->and($fight->player_hp)->toBeLessThanOrEqual(20 + $heal)
        ->and(bag()->potionCountByProfile($p->tg_id, ProfileEnum::HEAL))->toBe(0)
        ->and(implode("\n", $fight->log))->toContain((string) $heal);
});

it('win persists hp at least one even if session hp is zero', function (): void {
    Bus::fake();
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $p = characters()->createDraft(1403);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'WinFloor';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $enemy = $fight->enemy;
    $enemy['current_hp'] = 0;
    $fight->enemy = $enemy;
    $fight->player_hp = 0;
    $fight->player_stamina = 12;
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 55;
    $fight->turn_deadline_at = now()->subSecond();
    $fight->save();

    fakeRandom([0.99, 0.0, 0.0]);

    app()->call([new ResolveFightTurnTimeoutJob($fight->tg_id, $fight->turn_seq), 'handle']);

    expect(Fight::query()->whereKey($p->tg_id)->exists())->toBeFalse();

    $player = characters()->findByTgId($p->tg_id);
    expect($player->current_hp)->toBe(1)
        ->and($player->current_stamina)->toBe(12);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageCaption')
            && str_contains((string) $request['caption'], 'Победа');
    });
});

it('lose persists zero hp and zero stamina', function (): void {
    Bus::fake();
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $p = characters()->createDraft(1404);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'LoseZero';
    $p->current_stamina = 40;
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->player_hp = 1;
    $fight->player_stamina = 33;
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 66;
    $fight->turn_deadline_at = now()->subSecond();
    $fight->save();

    fakeRandom([0.99, 0.0, 0.75, 0.99, 0.99, 0.5]);

    app()->call([new ResolveFightTurnTimeoutJob($fight->tg_id, $fight->turn_seq), 'handle']);

    $player = characters()->findByTgId($p->tg_id);
    expect($player->current_hp)->toBe(0)
        ->and($player->current_stamina)->toBe(0);
});

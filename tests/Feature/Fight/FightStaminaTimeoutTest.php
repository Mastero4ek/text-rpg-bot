<?php

declare(strict_types=1);

use App\Enums\StanceEnum;
use App\Enums\ZoneEnum;
use App\Jobs\ResolveFightTurnTimeoutJob;
use App\Support\Game\Mf;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

it('scales combat mf by stamina ratio so zero stamina kills dodge', function (): void {
    fakeRandom([0.0]);

    $hitFull = combat()->calculateHit(
        fighter(['name' => 'Atk', 'agility' => 1, 'stance' => StanceEnum::ATTACK, 'stamina' => 30, 'maxStamina' => 30]),
        fighter([
            'name' => 'Def',
            'agility' => 30,
            'stance' => StanceEnum::DEFEND,
            'weaponMf' => new Mf(40, 0, 0, 0),
            'stamina' => 30,
            'maxStamina' => 30,
        ]),
        ZoneEnum::HEAD,
        [ZoneEnum::LEGS],
    );

    fakeRandom([0.99, 0.99, 0.5]);

    $hitEmpty = combat()->calculateHit(
        fighter(['name' => 'Atk', 'agility' => 1, 'stance' => StanceEnum::ATTACK, 'stamina' => 30, 'maxStamina' => 30]),
        fighter([
            'name' => 'Def',
            'agility' => 30,
            'stance' => StanceEnum::DEFEND,
            'weaponMf' => new Mf(40, 0, 0, 0),
            'stamina' => 0,
            'maxStamina' => 30,
        ]),
        ZoneEnum::HEAD,
        [ZoneEnum::LEGS],
    );

    expect($hitFull->dodged)->toBeTrue()
        ->and($hitEmpty->dodged)->toBeFalse()
        ->and($hitEmpty->critical)->toBeFalse()
        ->and($hitEmpty->dmg)->toBeGreaterThan(0);
});

it('drains more stamina for attack stance than defend stance', function (): void {
    $attackDrain = combat()->staminaDrainForAttacker(StanceEnum::ATTACK, false, false);
    $defendDrain = combat()->staminaDrainForAttacker(StanceEnum::DEFEND, false, false);

    expect($attackDrain)->toBeGreaterThan($defendDrain);
});

it('schedules turn timeout job on fight create', function (): void {
    Bus::fake();

    $p = characters()->createDraft(9101);
    $fight = fights()->createTraining($p, combat()->makeWoodenSoldier());

    expect($fight->player_max_stamina)->toBe(combat()->maxStamina($p->strength))
        ->and($fight->player_stamina)->toBe($fight->player_max_stamina)
        ->and($fight->turn_seq)->toBe(1)
        ->and($fight->turn_deadline_at)->not->toBeNull();

    Bus::assertDispatched(ResolveFightTurnTimeoutJob::class, function (ResolveFightTurnTimeoutJob $job) use ($fight): bool {
        return $job->tgId === $fight->tg_id && $job->turnSeq === $fight->turn_seq;
    });
});

it('resolveSkip applies timeout log and enemy hit without player attack', function (): void {
    Bus::fake();
    fakeRandom([0.99, 0.99, 0.99, 0.99, 0.99, 0.99]);

    $p = characters()->createDraft(9102);
    $p->username = 'SkipMe';
    $p->save();

    $fight = fights()->createTraining($p, combat()->makeWoodenSoldier());
    $fight->turn_deadline_at = now()->subSecond();
    $fight->save();

    $beforeHp = $fight->player_hp;
    $beforeStamina = $fight->player_stamina;
    $beforeSeq = $fight->turn_seq;

    $outcome = app(App\Services\Fight\FightRoundService::class)->resolveSkip($p);

    expect($outcome->kind)->toBe('continue')
        ->and($outcome->fight)->not->toBeNull();

    $fight = $outcome->fight;

    expect($fight->log)->toContain(__('combat.turn_timeout'))
        ->and($fight->player_hp)->toBeLessThanOrEqual($beforeHp)
        ->and($fight->player_stamina)->toBe($beforeStamina)
        ->and($fight->turn_seq)->toBe($beforeSeq + 1)
        ->and($fight->step->value)->toBe('STANCE');
});

it('timeout job no-ops when turn_seq is stale', function (): void {
    Bus::fake();
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $p = characters()->createDraft(9103);
    $fight = fights()->createTraining($p, combat()->makeWoodenSoldier());
    $staleSeq = $fight->turn_seq;
    $fight->turn_deadline_at = now()->subSecond();
    $fight->turn_seq = $staleSeq + 1;
    $fight->save();

    (new ResolveFightTurnTimeoutJob($fight->tg_id, $staleSeq))->handle(
        app(App\Services\Fight\FightRoundService::class),
        app(App\Services\Fight\FightService::class),
        app(App\Support\Telegram\FightStatusFormatter::class),
        app(App\Support\Telegram\TelegramClient::class),
        app(App\Services\Character\CharacterService::class),
        app(App\Services\Combat\CombatService::class),
        app(App\Actions\Inventory\InventoryApplyFightWearAction::class),
        app(App\Actions\Gem\GemBreakOnLoseAction::class),
        app(App\Services\Onboarding\OnboardingService::class),
        app(App\Services\Game\GameConfig::class),
    );

    $fight = fights()->findByTgId($p->tg_id);

    expect($fight->log)->toBe([])
        ->and($fight->turn_seq)->toBe($staleSeq + 1);
});

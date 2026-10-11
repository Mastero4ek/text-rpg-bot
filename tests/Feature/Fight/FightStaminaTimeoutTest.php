<?php

declare(strict_types=1);

use App\Enums\Combat\StanceEnum;
use App\Enums\Combat\ZoneEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\Fight\PlayerAttackEnum;
use App\Services\Fight\FightRoundService;
use App\Support\Mf;
use Illuminate\Support\Facades\Bus;

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

it('initializes stamina and turn_seq on fight create', function (): void {
    Bus::fake();

    $p = characters()->createDraft(9101);
    $fight = fights()->createTraining($p, woodenSoldier($p));

    expect($fight->player_max_stamina)->toBe(combat()->maxStamina($p->strength))
        ->and($fight->player_stamina)->toBe($fight->player_max_stamina)
        ->and($fight->turn_seq)->toBe(1)
        ->and($fight->turn_deadline_at)->toBeNull();
});

it('resolveSkip applies timeout log and enemy hit without player attack', function (): void {
    Bus::fake();
    fakeRandom([0.99, 0.99, 0.99, 0.99, 0.99, 0.99]);

    $p = characters()->createDraft(9102);
    $p->username = 'SkipMe';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));

    $beforeHp = $fight->player_hp;
    $beforeStamina = $fight->player_stamina;
    $beforeSeq = $fight->turn_seq;

    $outcome = app(FightRoundService::class)->runSkipRound($p);

    expect($outcome->kind)->toBe('continue')
        ->and($outcome->fight)->not->toBeNull();

    $fight = $outcome->fight;

    expect($fight->log)->toContain(__('combat.turn_timeout'))
        ->and($fight->player_hp)->toBeLessThanOrEqual($beforeHp)
        ->and($fight->player_stamina)->toBe($beforeStamina)
        ->and($fight->turn_seq)->toBe($beforeSeq + 1)
        ->and($fight->step->value)->toBe('STANCE');
});

it('clears partial wizard choice before skip so attack stance mf does not apply', function (): void {
    Bus::fake();

    // AI ATTACK stance, zones, then dodge roll 8%:
    // cleared stance → DEFEND mf dodges; leaked ATTACK mf would take the hit.
    fakeRandom([0.99, 0.0, 0.0, 0.08]);

    $p = characters()->createDraft(9105);
    $p->username = 'SkipStance';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->step = FightStepEnum::DEFEND;
    $fight->player_stance = StanceEnum::ATTACK;
    $fight->player_attack = PlayerAttackEnum::HEAD;
    $fight->player_attack_second = PlayerAttackEnum::CHEST;
    $fight->player_defend = ZoneEnum::LEGS;
    $fight->player_defend_second = ZoneEnum::HEAD;
    $fight->use_potion = true;
    $fight->save();

    $beforeHp = $fight->player_hp;

    $outcome = app(FightRoundService::class)->runSkipRound($p);

    expect($outcome->kind)->toBe('continue')
        ->and($outcome->fight)->not->toBeNull();

    $fight = $outcome->fight;

    expect($fight->player_hp)->toBe($beforeHp)
        ->and($fight->player_stance)->toBeNull()
        ->and($fight->player_attack)->toBeNull()
        ->and($fight->player_attack_second)->toBeNull()
        ->and($fight->player_defend)->toBeNull()
        ->and($fight->player_defend_second)->toBeNull()
        ->and($fight->use_potion)->toBeFalse()
        ->and($fight->log)->toContain(__('combat.turn_timeout'))
        ->and($fight->log)->toContain(__('combat.dodge', [
            'defender' => 'SkipStance',
            'zone' => __('combat.zone_acc.HEAD'),
        ]));
});

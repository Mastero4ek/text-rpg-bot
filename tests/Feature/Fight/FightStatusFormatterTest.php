<?php

declare(strict_types=1);

use App\Enums\Fight\FightStepEnum;
use App\Services\Fight\FightStatusFormatter;
use Illuminate\Support\Facades\Bus;

it('renders player and enemy blocks with stamina and level', function (): void {
    Bus::fake();

    $p = characters()->createDraft(9401);
    $p->username = 'BarHero';
    $p->level = 3;
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->player_stamina = 48;
    $fight->player_max_stamina = 60;
    $enemy = $fight->enemy;
    $enemy['stamina'] = 72;
    $enemy['maxStamina'] = 72;
    $enemy['current_hp'] = 180;
    $enemy['maxHp'] = 400;
    $enemy['level'] = 2;
    $fight->enemy = $enemy;
    $fight->player_hp = 210;
    $fight->player_max_hp = 350;
    $fight->log = ['Игрок бьёт в грудь на 42'];
    $fight->save();

    $status = app(FightStatusFormatter::class)->statusCaption($fight, $p);
    $panel = app(FightStatusFormatter::class)->panelCaption($fight, $p);

    expect($status)->toContain('BarHero')
        ->and($status)->toContain('[3]')
        ->and($status)->not->toContain('[3] :')
        ->and($status)->toContain('❤️ Здоровье: 210/350')
        ->and($status)->toContain('💚 Выносливость: 48/60')
        ->and($status)->toContain('[2]')
        ->and($status)->toContain('💚 Выносливость: 72/72')
        ->and($status)->not->toContain('⚔️ Атака:')
        ->and($status)->not->toContain('Игрок бьёт')
        ->and($status)->not->toContain('Раунд')
        ->and($panel)->toContain('Раунд')
        ->and($panel)->not->toContain('Игрок бьёт');
});

it('renders last round attack and defend without outcome icons', function (): void {
    Bus::fake();

    $p = characters()->createDraft(9402);
    $p->username = 'RoundHero';
    $p->level = 1;
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->player_hp = 50;
    $fight->player_max_hp = 100;
    $fight->last_round = [
        'skipped' => false,
        'player_hp_delta' => -14,
        'enemy_hp_delta' => -27,
        'player_attack' => 'BELLY',
        'player_attack_second' => null,
        'player_defend' => 'HEAD',
        'player_defend_second' => 'CHEST',
        'enemy_attack' => 'LEGS',
        'enemy_attack_second' => null,
        'enemy_defend' => 'BELLY',
        'enemy_defend_second' => null,
    ];
    $fight->save();

    $status = app(FightStatusFormatter::class)->statusCaption($fight, $p);

    expect($status)->toContain('❤️ Здоровье: 50/100')
        ->and($status)->toContain('⚔️ Атака: ' . __('combat.zone_label.BELLY'))
        ->and($status)->toContain('🛡️ Защита: ' . __('combat.zone_label.HEAD') . ', ' . __('combat.zone_label.CHEST'))
        ->and($status)->not->toContain('✅')
        ->and($status)->not->toContain('❌');
});

it('hides player attack lines after skipped round', function (): void {
    Bus::fake();

    $p = characters()->createDraft(9403);
    $p->username = 'SkipHero';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->last_round = [
        'skipped' => true,
        'player_hp_delta' => -11,
        'enemy_hp_delta' => 0,
        'player_attack' => null,
        'player_attack_second' => null,
        'player_defend' => null,
        'player_defend_second' => null,
        'enemy_attack' => 'HEAD',
        'enemy_attack_second' => null,
        'enemy_defend' => 'LEGS',
        'enemy_defend_second' => null,
    ];
    $fight->save();

    $status = app(FightStatusFormatter::class)->statusCaption($fight, $p);

    expect($status)->toContain('⚔️ Атака: ' . __('combat.zone_label.HEAD'))
        ->and($status)->not->toMatch('/RoundHero[\s\S]*⚔️ Атака:/');
});

it('puts step prompt into panel caption', function (): void {
    Bus::fake();

    $p = characters()->createDraft(9404);
    $p->username = 'PromptHero';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->step = FightStepEnum::STANCE;
    $fight->log = [];
    $fight->save();

    $panel = app(FightStatusFormatter::class)->panelCaption($fight, $p);

    expect($panel)->toContain(__('combat.log_prompt.STANCE', ['round' => max(1, $fight->turn_seq)]))
        ->and($panel)->toContain('PromptHero');
});

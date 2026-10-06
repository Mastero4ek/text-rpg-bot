<?php

declare(strict_types=1);

use App\Support\Telegram\FightStatusFormatter;
use Illuminate\Support\Facades\Bus;

it('includes player and enemy stamina bars in fight status', function (): void {
    Bus::fake();

    $p = characters()->createDraft(9401);
    $p->username = 'BarHero';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->player_stamina = 48;
    $fight->player_max_stamina = 60;
    $enemy = $fight->enemy;
    $enemy['stamina'] = 72;
    $enemy['maxStamina'] = 72;
    $enemy['current_hp'] = 180;
    $enemy['maxHp'] = 400;
    $fight->enemy = $enemy;
    $fight->player_hp = 210;
    $fight->player_max_hp = 350;
    $fight->log = ['Игрок бьёт в грудь на 42'];
    $fight->save();

    $text = app(FightStatusFormatter::class)->format($fight, $p->username);

    expect($text)->toContain(__('combat.status', [
        'player' => 'BarHero',
        'hp' => 210,
        'maxHp' => 350,
        'stamina' => 48,
        'maxStamina' => 60,
        'enemy' => $enemy['name'],
        'ehp' => 180,
        'emax' => 400,
        'estamina' => 72,
        'emaxStamina' => 72,
    ]))
        ->and($text)->toContain('💚48/60')
        ->and($text)->toContain('💚72/72')
        ->and($text)->toContain('Игрок бьёт в грудь на 42');
});

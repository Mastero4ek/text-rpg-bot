<?php

declare(strict_types=1);

use App\Enums\Quest\QuestPhaseEnum;
use App\Services\Quest\QuestNpcPlacement;

it('places overseer at gates before train and in tavern after flags', function (): void {
    $player = cityArrived(9730, 'NpcPlace');
    $placement = app(QuestNpcPlacement::class);

    expect($placement->isOverseerAtGates($player))->toBeTrue()
        ->and($placement->isOverseerAtTavern($player))->toBeFalse();

    $player->overseer_at_tavern = true;
    $player->overseer_trained = true;
    $player->quest_phase = QuestPhaseEnum::Healer;
    $player->save();
    $player->refresh();

    expect($placement->isOverseerAtGates($player))->toBeFalse()
        ->and($placement->isOverseerAtTavern($player))->toBeTrue();
});

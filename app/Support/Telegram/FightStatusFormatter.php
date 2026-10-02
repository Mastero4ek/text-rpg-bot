<?php

declare(strict_types=1);

namespace App\Support\Telegram;

use App\Models\Fight;
use App\Services\Fight\FightService;

final class FightStatusFormatter
{
    public function __construct(
        private readonly FightService $fights,
    ) {}

    public function format(Fight $fight, ?string $playerName): string
    {
        $enemy = $this->fights->enemy($fight);

        if ($playerName === null) {
            $name = __('common.you');
        } else {
            $name = $playerName;
        }

        $head = __('combat.status', [
            'player' => $name,
            'hp' => $fight->player_hp,
            'maxHp' => $fight->player_max_hp,
            'enemy' => $enemy->name,
            'ehp' => $enemy->currentHp,
            'emax' => $enemy->maxHp,
        ]);

        $log = $fight->log;

        if ($log === []) {
            return $head;
        }

        $tail = array_slice($log, -6);
        $lines = [$head, ''];

        foreach ($tail as $line) {
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}

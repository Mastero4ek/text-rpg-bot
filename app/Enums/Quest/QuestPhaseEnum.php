<?php

declare(strict_types=1);

namespace App\Enums\Quest;

use Filament\Support\Contracts\HasLabel;

enum QuestPhaseEnum: string implements HasLabel
{
    case Overseer = 'Overseer';
    case Healer = 'Healer';
    case Blacksmith = 'Blacksmith';
    case Buyer = 'Buyer';
    case Bruiser = 'Bruiser';
    case Done = 'Done';

    public function getLabel(): string
    {
        return __('admin.quest_phases.' . $this->value);
    }
}

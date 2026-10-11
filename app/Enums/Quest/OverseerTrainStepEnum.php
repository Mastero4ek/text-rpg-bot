<?php

declare(strict_types=1);

namespace App\Enums\Quest;

use Filament\Support\Contracts\HasLabel;

enum OverseerTrainStepEnum: string implements HasLabel
{
    case Intro = 'Intro';
    case Fight = 'Fight';
    case Stats = 'Stats';

    public function getLabel(): string
    {
        return __('admin.overseer_train_steps.' . $this->value);
    }
}

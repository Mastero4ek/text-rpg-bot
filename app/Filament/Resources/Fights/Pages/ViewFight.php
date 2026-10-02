<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fights\Pages;

use App\Filament\Resources\Fights\FightResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewFight extends ViewRecord
{
    protected static string $resource = FightResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

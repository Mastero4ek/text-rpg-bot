<?php

declare(strict_types=1);

namespace App\Filament\Resources\Equipment\Pages;

use App\Filament\Concerns\CanCloneToCreate;
use App\Filament\Concerns\HasBetweenFormActions;
use App\Filament\Resources\Equipment\EquipmentResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateEquipment extends CreateRecord
{
    use CanCloneToCreate;
    use HasBetweenFormActions;

    protected static string $resource = EquipmentResource::class;

    protected static bool $canCreateAnother = false;

    public function mount(): void
    {
        parent::mount();

        $this->fillFormFromClone();
    }
}

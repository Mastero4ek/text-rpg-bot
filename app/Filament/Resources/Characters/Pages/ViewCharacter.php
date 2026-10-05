<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\Pages;

use App\Filament\Resources\Characters\CharacterResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;

final class ViewCharacter extends ViewRecord
{
    protected static string $resource = CharacterResource::class;

    #[On('character-vitals-changed')]
    public function refreshVitalsFromInventory(): void
    {
        $this->getRecord()->refresh();
        $this->refreshFormData([
            'current_hp',
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

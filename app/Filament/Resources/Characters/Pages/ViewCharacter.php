<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\Pages;

use App\Actions\Character\CharacterSyncSkinMediaAction;
use App\Enums\SkinEnum;
use App\Filament\Resources\Characters\CharacterResource;
use App\Models\Character\Character;
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if (
            $record instanceof Character
            && $record->skin instanceof SkinEnum
            && ! $record->hasMedia('image')
        ) {
            app(CharacterSyncSkinMediaAction::class)->handle($record);
        }

        return $data;
    }
}

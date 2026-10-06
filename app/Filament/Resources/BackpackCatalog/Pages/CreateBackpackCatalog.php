<?php

declare(strict_types=1);

namespace App\Filament\Resources\Equipment\Pages;

use App\Enums\Equipment\ProfileEnum;
use App\Filament\Concerns\HasCloneToCreate;
use App\Filament\Concerns\HasFormActionsBetween;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Resources\Equipment\Schemas\EquipmentForm;
use App\Models\Equipment;
use Filament\Resources\Pages\CreateRecord;

final class CreateEquipment extends CreateRecord
{
    use HasCloneToCreate;
    use HasFormActionsBetween;

    protected static string $resource = EquipmentResource::class;

    protected static bool $canCreateAnother = false;

    public function mount(): void
    {
        parent::mount();

        $this->fillFormFromClone();
        $this->assignGeneratedItemId();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $profile = null;

        if (($data['profile'] ?? null) instanceof ProfileEnum) {
            $profile = $data['profile'];
        } elseif (is_string($data['profile'] ?? null) && $data['profile'] !== '') {
            $profile = ProfileEnum::tryFrom($data['profile']);
        }

        if ($profile instanceof ProfileEnum) {
            $data['item_id'] = Equipment::nextItemIdForProfile($profile);
        }

        return EquipmentForm::sanitizeCatalogFieldsForType($data);
    }

    private function assignGeneratedItemId(): void
    {
        $rawProfile = $this->data['profile'] ?? null;
        $profile = null;

        if ($rawProfile instanceof ProfileEnum) {
            $profile = $rawProfile;
        } elseif (is_string($rawProfile) && $rawProfile !== '') {
            $profile = ProfileEnum::tryFrom($rawProfile);
        }

        if (! $profile instanceof ProfileEnum) {
            return;
        }

        $this->data['item_id'] = Equipment::nextItemIdForProfile($profile);
    }
}

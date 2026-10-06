<?php

declare(strict_types=1);

namespace App\Filament\Resources\BackpackCatalog\Pages;

use App\Enums\Equipment\ProfileEnum;
use App\Filament\Concerns\HasCloneToCreate;
use App\Filament\Concerns\HasFormActionsBetween;
use App\Filament\Resources\BackpackCatalog\BackpackCatalogResource;
use App\Filament\Resources\BackpackCatalog\Schemas\BackpackCatalogForm;
use App\Models\Backpack\BackpackCatalog;
use Filament\Resources\Pages\CreateRecord;

final class CreateBackpackCatalog extends CreateRecord
{
    use HasCloneToCreate;
    use HasFormActionsBetween;

    protected static string $resource = BackpackCatalogResource::class;

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
            $data['catalog_id'] = BackpackCatalog::nextCatalogIdForProfile($profile);
        }

        return BackpackCatalogForm::sanitizeCatalogFieldsForType($data);
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

        $this->data['catalog_id'] = BackpackCatalog::nextCatalogIdForProfile($profile);
    }
}

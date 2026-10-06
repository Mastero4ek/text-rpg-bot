<?php

declare(strict_types=1);

namespace App\Filament\Resources\BagCatalog\Pages;

use App\Enums\Bag\BagKindEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Gem\GemTypeEnum;
use App\Filament\Concerns\HasCloneToCreate;
use App\Filament\Concerns\HasFormActionsBetween;
use App\Filament\Resources\BagCatalog\BagCatalogResource;
use App\Models\BagCatalog;
use Filament\Resources\Pages\CreateRecord;

final class CreateBagCatalog extends CreateRecord
{
    use HasCloneToCreate;
    use HasFormActionsBetween;

    protected static string $resource = BagCatalogResource::class;

    protected static bool $canCreateAnother = false;

    public function mount(): void
    {
        parent::mount();

        $this->fillFormFromClone();
        $this->assignGeneratedCatalogId();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $kind = self::kindFrom($data['kind'] ?? null);

        if ($kind === BagKindEnum::GEM) {
            $type = self::gemTypeFrom($data['type'] ?? null);

            if ($type instanceof GemTypeEnum) {
                $data['catalog_id'] = BagCatalog::nextCatalogIdForType($type);
            }

            $data['profile'] = null;
            $data['effect_value'] = null;
        }

        if ($kind === BagKindEnum::POTION) {
            $profile = self::profileFrom($data['profile'] ?? null);

            if ($profile instanceof ProfileEnum) {
                $data['catalog_id'] = BagCatalog::nextCatalogIdForProfile($profile);
            }

            $data['type'] = null;
            $data['max_durability'] = null;
            $data['mf_dodge'] = 0;
            $data['mf_anti_dodge'] = 0;
            $data['mf_crit'] = 0;
            $data['mf_anti_crit'] = 0;
        }

        if ($kind instanceof BagKindEnum) {
            $data['kind'] = $kind;
        }

        return $data;
    }

    private static function gemTypeFrom(mixed $raw): ?GemTypeEnum
    {
        if ($raw instanceof GemTypeEnum) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            return GemTypeEnum::tryFrom($raw);
        }

        return null;
    }

    private static function kindFrom(mixed $raw): ?BagKindEnum
    {
        if ($raw instanceof BagKindEnum) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            return BagKindEnum::tryFrom($raw);
        }

        return null;
    }

    private static function profileFrom(mixed $raw): ?ProfileEnum
    {
        if ($raw instanceof ProfileEnum) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            return ProfileEnum::tryFrom($raw);
        }

        return null;
    }

    private function assignGeneratedCatalogId(): void
    {
        $kind = self::kindFrom($this->data['kind'] ?? null);

        if ($kind === BagKindEnum::GEM) {
            $type = self::gemTypeFrom($this->data['type'] ?? null);

            if (! $type instanceof GemTypeEnum) {
                return;
            }

            $this->data['catalog_id'] = BagCatalog::nextCatalogIdForType($type);

            return;
        }

        if ($kind === BagKindEnum::POTION) {
            $profile = self::profileFrom($this->data['profile'] ?? null);

            if (! $profile instanceof ProfileEnum) {
                return;
            }

            $this->data['catalog_id'] = BagCatalog::nextCatalogIdForProfile($profile);
        }
    }
}

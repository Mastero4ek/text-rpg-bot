<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gems\Pages;

use App\Enums\Gem\GemTypeEnum;
use App\Filament\Concerns\HasCloneToCreate;
use App\Filament\Concerns\HasFormActionsBetween;
use App\Filament\Resources\Gems\GemResource;
use App\Models\Gem;
use Filament\Resources\Pages\CreateRecord;

final class CreateGem extends CreateRecord
{
    use HasCloneToCreate;
    use HasFormActionsBetween;

    protected static string $resource = GemResource::class;

    protected static bool $canCreateAnother = false;

    public function mount(): void
    {
        parent::mount();

        $this->fillFormFromClone();
        $this->assignGeneratedGemId();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $type = null;

        if (($data['type'] ?? null) instanceof GemTypeEnum) {
            $type = $data['type'];
        } elseif (is_string($data['type'] ?? null) && $data['type'] !== '') {
            $type = GemTypeEnum::tryFrom($data['type']);
        }

        if ($type instanceof GemTypeEnum) {
            $data['gem_id'] = Gem::nextGemIdForType($type);
        }

        return $data;
    }

    private function assignGeneratedGemId(): void
    {
        $rawType = $this->data['type'] ?? null;
        $type = null;

        if ($rawType instanceof GemTypeEnum) {
            $type = $rawType;
        } elseif (is_string($rawType) && $rawType !== '') {
            $type = GemTypeEnum::tryFrom($rawType);
        }

        if (! $type instanceof GemTypeEnum) {
            return;
        }

        $this->data['gem_id'] = Gem::nextGemIdForType($type);
    }
}

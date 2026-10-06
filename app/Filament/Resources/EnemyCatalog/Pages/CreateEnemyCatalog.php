<?php

declare(strict_types=1);

namespace App\Filament\Resources\EnemyCatalog\Pages;

use App\Enums\Enemy\EnemyKindEnum;
use App\Filament\Concerns\HasCloneToCreate;
use App\Filament\Concerns\HasFormActionsBetween;
use App\Filament\Resources\EnemyCatalog\EnemyCatalogResource;
use App\Models\Enemy\EnemyCatalog;
use Filament\Resources\Pages\CreateRecord;

final class CreateEnemyCatalog extends CreateRecord
{
    use HasCloneToCreate;
    use HasFormActionsBetween;

    protected static string $resource = EnemyCatalogResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return __('admin.models.enemy_catalog.create');
    }

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
        $kindRaw = null;

        if (array_key_exists('kind', $data)) {
            $kindRaw = $data['kind'];
        }

        $kind = self::kindFrom($kindRaw);

        if ($kind instanceof EnemyKindEnum) {
            $data['catalog_id'] = EnemyCatalog::nextCatalogIdForKind($kind);
            $data['kind'] = $kind;
        }

        return $data;
    }

    private static function kindFrom(mixed $raw): ?EnemyKindEnum
    {
        if ($raw instanceof EnemyKindEnum) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            return EnemyKindEnum::tryFrom($raw);
        }

        return null;
    }

    private function assignGeneratedCatalogId(): void
    {
        $kindRaw = null;

        if (array_key_exists('kind', $this->data)) {
            $kindRaw = $this->data['kind'];
        }

        $kind = self::kindFrom($kindRaw);

        if (! $kind instanceof EnemyKindEnum) {
            return;
        }

        $this->data['catalog_id'] = EnemyCatalog::nextCatalogIdForKind($kind);
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\CityCatalog\Pages;

use App\Filament\Concerns\HasFormActionsBetween;
use App\Filament\Resources\CityCatalog\CityCatalogResource;
use App\Models\City;
use Filament\Resources\Pages\CreateRecord;
use RuntimeException;

final class CreateCityCatalog extends CreateRecord
{
    use HasFormActionsBetween;

    protected static string $resource = CityCatalogResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! array_key_exists('name', $data) || ! is_string($data['name']) || $data['name'] === '') {
            throw new RuntimeException('City name missing.');
        }

        $data['key'] = City::nextKeyForName($data['name']);

        return $data;
    }
}

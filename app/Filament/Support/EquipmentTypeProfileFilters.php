<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\Equipment\ProfileEnum;
use App\Enums\Equipment\TypeEnum;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;

final class EquipmentTypeProfileFilters
{
    public static function itemType(): SelectFilter
    {
        return SelectFilter::make('item_type')
            ->label(__('admin.labels.item_type'))
            ->native(false)
            ->options(TypeEnum::class)
            ->modifyFormFieldUsing(
                fn (Select $field): Select => $field
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('../../profile/value', null);
                    }),
            );
    }

    public static function profile(): SelectFilter
    {
        return SelectFilter::make('profile')
            ->label(__('admin.labels.profile'))
            ->native(false)
            ->options(fn (HasTable $livewire): array => ProfileEnum::optionsForType(
                self::selectedType($livewire),
            ));
    }

    private static function selectedType(HasTable $livewire): ?TypeEnum
    {
        $state = $livewire->getTableFilterFormState('item_type');

        if (! is_array($state)) {
            return null;
        }

        if (! array_key_exists('value', $state)) {
            return null;
        }

        $raw = $state['value'];

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return TypeEnum::tryFrom($raw);
    }
}

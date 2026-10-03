<?php

declare(strict_types=1);

namespace App\Filament\Resources\Equipment\Pages;

use App\Filament\Concerns\HasBetweenFormActions;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Models\Equipment;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Colors\Color;

final class EditEquipment extends EditRecord
{
    use HasBetweenFormActions;

    protected static string $resource = EquipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label(__('admin.actions.archive.label'))
                ->modalHeading(fn (Equipment $record): string => __('admin.actions.archive.modal_heading', [
                    'label' => $record->name,
                ]))
                ->modalSubmitActionLabel(__('admin.actions.archive.modal_submit'))
                ->successNotificationTitle(__('admin.actions.archive.notification'))
                ->color(Color::Amber),
            RestoreAction::make()
                ->label(__('admin.actions.restore.label'))
                ->modalHeading(fn (Equipment $record): string => __('admin.actions.restore.modal_heading', [
                    'label' => $record->name,
                ]))
                ->modalSubmitActionLabel(__('admin.actions.restore.modal_submit'))
                ->color(Color::Green)
                ->successNotificationTitle(__('admin.actions.restore.notification')),
            ForceDeleteAction::make()
                ->label(__('admin.actions.delete.label'))
                ->modalHeading(fn (Equipment $record): string => __('admin.actions.delete.modal_heading', [
                    'label' => $record->name,
                ]))
                ->modalSubmitActionLabel(__('admin.actions.delete.modal_submit'))
                ->successNotificationTitle(__('admin.actions.delete.notification'))
                ->visible(fn (Equipment $record): bool => $record->trashed() && ! $record->isReferencedByInventory()),
        ];
    }
}

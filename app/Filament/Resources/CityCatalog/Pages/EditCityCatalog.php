<?php

declare(strict_types=1);

namespace App\Filament\Resources\CityCatalog\Pages;

use App\Filament\Concerns\HasFormActionsBetween;
use App\Filament\Resources\CityCatalog\CityCatalogResource;
use App\Models\City;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;

final class EditCityCatalog extends EditRecord
{
    use HasFormActionsBetween;

    protected static string $resource = CityCatalogResource::class;

    public function content(Schema $schema): Schema
    {
        if ($this->hasCombinedRelationManagerTabsWithContent()) {
            return parent::content($schema);
        }

        return $schema
            ->components([
                EmbeddedSchema::make('form'),
                $this->getRelationManagersContentComponent(),
                $this->getFormActionsContentComponent(),
            ]);
    }

    public function hasFormWrapper(): bool
    {
        return false;
    }

    protected function getHeaderActions(): array
    {
        return [
            CityCatalogResource::configureArchiveAction(
                DeleteAction::make()
                    ->label(__('admin.actions.archive.label'))
                    ->color(Color::Amber),
            ),
            RestoreAction::make()
                ->label(__('admin.actions.restore.label'))
                ->modalHeading(fn (City $record): string => __('admin.actions.restore.modal_heading', [
                    'label' => $record->name,
                ]))
                ->modalSubmitActionLabel(__('admin.actions.restore.modal_submit'))
                ->color(Color::Green)
                ->successNotificationTitle(__('admin.actions.restore.notification')),
            ForceDeleteAction::make()
                ->label(__('admin.actions.delete.label'))
                ->modalHeading(fn (City $record): string => __('admin.actions.delete.modal_heading', [
                    'label' => $record->name,
                ]))
                ->modalSubmitActionLabel(__('admin.actions.delete.modal_submit'))
                ->successNotificationTitle(__('admin.actions.delete.notification'))
                ->visible(fn (City $record): bool => $record->trashed() && ! $record->isReferencedByCharacters()),
        ];
    }
}

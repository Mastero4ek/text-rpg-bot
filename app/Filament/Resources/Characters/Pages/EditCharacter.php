<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\Pages;

use App\Actions\Character\CharacterDeleteAction;
use App\Filament\Concerns\HasFormActionsBetween;
use App\Filament\Resources\Characters\CharacterResource;
use App\Models\Character;
use App\Services\Character\CharacterService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;
use RuntimeException;

final class EditCharacter extends EditRecord
{
    use HasFormActionsBetween;

    protected static string $resource = CharacterResource::class;

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
            DeleteAction::make()
                ->label(__('admin.actions.archive.label'))
                ->modalHeading(fn (Character $record): string => __('admin.actions.archive.modal_heading', [
                    'label' => self::recordLabel($record),
                ]))
                ->modalSubmitActionLabel(__('admin.actions.archive.modal_submit'))
                ->successNotificationTitle(__('admin.actions.archive.notification'))
                ->color(Color::Amber),
            RestoreAction::make()
                ->label(__('admin.actions.restore.label'))
                ->modalHeading(fn (Character $record): string => __('admin.actions.restore.modal_heading', [
                    'label' => self::recordLabel($record),
                ]))
                ->modalSubmitActionLabel(__('admin.actions.restore.modal_submit'))
                ->color(Color::Green)
                ->successNotificationTitle(__('admin.actions.restore.notification')),
            ForceDeleteAction::make()
                ->label(__('admin.actions.delete.label'))
                ->modalHeading(fn (Character $record): string => __('admin.actions.delete.modal_heading', [
                    'label' => self::recordLabel($record),
                ]))
                ->modalSubmitActionLabel(__('admin.actions.delete.modal_submit'))
                ->successNotificationTitle(__('admin.actions.delete.notification'))
                ->visible(fn (Character $record): bool => $record->trashed())
                ->using(function (Character $record): bool {
                    app(CharacterDeleteAction::class)->handle($record);

                    return true;
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Character
    {
        if (! $record instanceof Character) {
            throw new RuntimeException('Expected Character.');
        }

        $characters = app(CharacterService::class);
        $oldExp = $record->exp;

        $record->fill($data);

        if ($record->exp > $oldExp) {
            $characters->applyExperienceThresholds($record, $oldExp, $record->exp);
        }

        $record->current_hp = $characters->clampHp(
            $record->current_hp,
            $characters->maxHp($record),
        );
        $record->current_stamina = $characters->clampStamina(
            $record->current_stamina,
            $characters->maxStamina($record),
        );

        $record->save();

        return $record;
    }

    private static function recordLabel(Character $record): string
    {
        if (is_string($record->username) && $record->username !== '') {
            return $record->username;
        }

        return (string) $record->tg_id;
    }
}

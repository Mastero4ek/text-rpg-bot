<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\Pages;

use App\Actions\Character\CharacterDeleteAction;
use App\Actions\Character\CharacterSyncSkinMediaAction;
use App\Enums\SkinEnum;
use App\Filament\Concerns\HasFormActionsBetween;
use App\Filament\Resources\Characters\CharacterResource;
use App\Filament\Resources\Characters\RelationManagers\BackpackRelationManager;
use App\Filament\Resources\Characters\RelationManagers\BagRelationManager;
use App\Filament\Resources\Characters\RelationManagers\LoadoutRelationManager;
use App\Models\Character;
use App\Services\CharacterService;
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

    public function refreshAfterQuestScreen(): void
    {
        $this->getRecord()->refresh();
        $this->fillForm();

        $this->dispatch('character-quest-screen-changed')->to(LoadoutRelationManager::class);
        $this->dispatch('character-quest-screen-changed')->to(BackpackRelationManager::class);
        $this->dispatch('character-quest-screen-changed')->to(BagRelationManager::class);
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
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach ([
            'quest_chapter',
            'quest_phase',
            'progress_step',
            'unlock_portal',
            'unlock_forest',
            'unlock_board',
            'unlock_arena',
            'city_unlocked_full',
            'overseer_trained',
            'overseer_at_tavern',
            'overseer_train_step',
            'healer_at_tavern',
            'healer_meet_open',
            'blacksmith_at_tavern',
            'buyer_at_tavern',
            'bruiser_contract_active',
            'bruiser_knuckle_pending',
            'healer_debt_open',
            'healer_rat_loot_pending',
        ] as $key) {
            unset($data[$key]);
        }

        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, 'quest_screen_')) {
                unset($data[$key]);
            }
        }

        return $data;
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
        $skinBefore = $record->skin;

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

        $skinChanged = $skinBefore !== $record->skin;

        if (
            $record->skin instanceof SkinEnum
            && ($skinChanged || ! $record->hasMedia('image'))
        ) {
            app(CharacterSyncSkinMediaAction::class)->handle($record);
        }

        return $record->fresh() ?? $record;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if (
            $record instanceof Character
            && $record->skin instanceof SkinEnum
            && ! $record->hasMedia('image')
        ) {
            app(CharacterSyncSkinMediaAction::class)->handle($record);
        }

        return $data;
    }

    private static function recordLabel(Character $record): string
    {
        if (is_string($record->username) && $record->username !== '') {
            return $record->username;
        }

        return (string) $record->tg_id;
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fights\Pages;

use App\Actions\Fight\FightClearAction;
use App\Filament\Resources\Fights\FightResource;
use App\Models\Fight;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Colors\Color;

final class ViewFight extends ViewRecord
{
    protected static string $resource = FightResource::class;

    public function getTitle(): string
    {
        return __('admin.pages.fight.view');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Fight) {
            return $data;
        }

        $username = $record->character?->username;

        if (is_string($username) && $username !== '') {
            $data['character'] = [
                'username' => $username,
            ];

            return $data;
        }

        $data['character'] = [
            'username' => (string) $record->tg_id,
        ];

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->color(Color::Red)
                ->label(__('admin.actions.delete.label'))
                ->modalHeading(fn (Fight $record): string => __('admin.actions.delete.modal_heading', [
                    'label' => FightResource::getRecordTitle($record) ?? (string) $record->tg_id,
                ]))
                ->modalSubmitActionLabel(__('admin.actions.delete.modal_submit'))
                ->successNotificationTitle(__('admin.actions.delete.notification'))
                ->using(function (Fight $record): bool {
                    app(FightClearAction::class)->handle($record->tg_id);

                    return true;
                }),
        ];
    }
}

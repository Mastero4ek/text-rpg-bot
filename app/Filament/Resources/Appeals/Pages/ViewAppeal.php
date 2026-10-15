<?php

declare(strict_types=1);

namespace App\Filament\Resources\Appeals\Pages;

use App\Actions\Appeal\AppealCloseAction;
use App\Actions\Appeal\AppealMarkReadAction;
use App\Filament\Resources\Appeals\AppealResource;
use App\Models\Appeal\Appeal;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Colors\Color;

final class ViewAppeal extends ViewRecord
{
    protected static string $resource = AppealResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $ticket = $this->getRecord();

        if (! $ticket instanceof Appeal) {
            return;
        }

        if (! $ticket->status->canMarkRead()) {
            return;
        }

        $updated = app(AppealMarkReadAction::class)->handle($ticket);
        $this->record = $updated;
        $this->refreshFormData(['status']);
    }

    public function getTitle(): string
    {
        return __('admin.pages.appeal.view');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Appeal) {
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
            Action::make('close')
                ->label(__('admin.actions.appeal_close.label'))
                ->color(Color::Gray)
                ->visible(function (): bool {
                    $record = $this->getRecord();

                    if (! $record instanceof Appeal) {
                        return false;
                    }

                    return $record->status->canClose();
                })
                ->requiresConfirmation()
                ->modalHeading(__('admin.actions.appeal_close.modal_heading'))
                ->modalSubmitActionLabel(__('admin.actions.appeal_close.modal_submit'))
                ->successNotificationTitle(__('admin.actions.appeal_close.notification'))
                ->action(function (): void {
                    $record = $this->getRecord();

                    if (! $record instanceof Appeal) {
                        return;
                    }

                    $updated = app(AppealCloseAction::class)->handle($record);
                    $this->record = $updated;
                    $this->refreshFormData(['status']);
                }),
            DeleteAction::make()
                ->color(Color::Red)
                ->label(__('admin.actions.delete.label'))
                ->modalHeading(fn (Appeal $record): string => __('admin.actions.delete.modal_heading', [
                    'label' => AppealResource::getRecordTitle($record) ?? (string) $record->id,
                ]))
                ->modalSubmitActionLabel(__('admin.actions.delete.modal_submit'))
                ->successNotificationTitle(__('admin.actions.delete.notification')),
        ];
    }
}

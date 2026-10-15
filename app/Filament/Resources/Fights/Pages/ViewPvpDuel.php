<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fights\Pages;

use App\Actions\Pvp\PvpDuelClearAction;
use App\Filament\Resources\Fights\PvpFightResource;
use App\Filament\Resources\Fights\Schemas\PvpDuelForm;
use App\Models\Fight;
use App\Models\Pvp\PvpDuel;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Colors\Color;

final class ViewPvpDuel extends ViewRecord
{
    protected static string $resource = PvpFightResource::class;

    public function getTitle(): string
    {
        return __('admin.pages.pvp_duel.view');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if (! $record instanceof PvpDuel) {
            return $data;
        }

        $fightA = PvpDuelForm::sideFight($record, $record->a_tg_id);
        $fightB = PvpDuelForm::sideFight($record, $record->b_tg_id);

        $data['side_a_username'] = PvpDuelForm::sideUsername($record->sideA?->username, $record->a_tg_id);
        $data['side_b_username'] = PvpDuelForm::sideUsername($record->sideB?->username, $record->b_tg_id);
        $data['side_a_hp'] = self::fightHp($fightA);
        $data['side_a_max_hp'] = self::fightMaxHp($fightA);
        $data['side_a_stamina'] = self::fightStamina($fightA);
        $data['side_a_max_stamina'] = self::fightMaxStamina($fightA);
        $data['side_b_hp'] = self::fightHp($fightB);
        $data['side_b_max_hp'] = self::fightMaxHp($fightB);
        $data['side_b_stamina'] = self::fightStamina($fightB);
        $data['side_b_max_stamina'] = self::fightMaxStamina($fightB);

        if ($fightA?->turn_deadline_at !== null) {
            $data['round_deadline_at'] = $fightA->turn_deadline_at;
        } elseif ($fightB?->turn_deadline_at !== null) {
            $data['round_deadline_at'] = $fightB->turn_deadline_at;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->color(Color::Red)
                ->label(__('admin.actions.delete.label'))
                ->modalHeading(fn (PvpDuel $record): string => __('admin.actions.delete.modal_heading', [
                    'label' => PvpFightResource::getRecordTitle($record) ?? (string) $record->id,
                ]))
                ->modalSubmitActionLabel(__('admin.actions.delete.modal_submit'))
                ->successNotificationTitle(__('admin.actions.delete.notification'))
                ->using(function (PvpDuel $record): bool {
                    app(PvpDuelClearAction::class)->handle($record);

                    return true;
                }),
        ];
    }

    private static function fightHp(?Fight $fight): ?int
    {
        if (! $fight instanceof Fight) {
            return null;
        }

        return $fight->player_hp;
    }

    private static function fightMaxHp(?Fight $fight): ?int
    {
        if (! $fight instanceof Fight) {
            return null;
        }

        return $fight->player_max_hp;
    }

    private static function fightMaxStamina(?Fight $fight): ?int
    {
        if (! $fight instanceof Fight) {
            return null;
        }

        return $fight->player_max_stamina;
    }

    private static function fightStamina(?Fight $fight): ?int
    {
        if (! $fight instanceof Fight) {
            return null;
        }

        return $fight->player_stamina;
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fights\Schemas;

use App\Enums\Pvp\PvpDuelStatusEnum;
use App\Filament\Support\CrossedSwordsIcon;
use App\Filament\Support\FightPortraitUpload;
use App\Models\Fight;
use App\Models\Pvp\PvpDuel;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\VerticalAlignment;
use Filament\Support\Icons\Heroicon;

final class PvpDuelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('admin.sections.pvp_duel'))
                    ->icon(Heroicon::OutlinedIdentification)
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Group::make([
                            Select::make('status')
                                ->label(__('admin.labels.pvp_duel_status'))
                                ->options(PvpDuelStatusEnum::class)
                                ->native(false),
                            TextInput::make('round')
                                ->label(__('admin.labels.pvp_round'))
                                ->numeric(),
                            DateTimePicker::make('round_deadline_at')
                                ->label(__('admin.labels.round_deadline_at'))
                                ->displayFormat('d.m.Y H:i:s')
                                ->seconds(true)
                                ->native(false),
                        ])
                            ->columns(3)
                            ->columnSpanFull(),
                        Flex::make([
                            Group::make([
                                FightPortraitUpload::forDuelSideA(),
                                TextInput::make('side_a_username')
                                    ->label(__('admin.labels.username')),
                                Group::make([
                                    TextInput::make('side_a_hp')
                                        ->label(__('admin.labels.player_hp'))
                                        ->numeric(),
                                    TextInput::make('side_a_max_hp')
                                        ->label(__('admin.labels.player_max_hp'))
                                        ->numeric(),
                                    TextInput::make('side_a_stamina')
                                        ->label(__('admin.labels.player_stamina'))
                                        ->numeric(),
                                    TextInput::make('side_a_max_stamina')
                                        ->label(__('admin.labels.player_max_stamina'))
                                        ->numeric(),
                                ])
                                    ->columns(2),
                            ]),
                            Html::make(CrossedSwordsIcon::html())
                                ->grow(false),
                            Group::make([
                                FightPortraitUpload::forDuelSideB(),
                                TextInput::make('side_b_username')
                                    ->label(__('admin.labels.enemy')),
                                Group::make([
                                    TextInput::make('side_b_hp')
                                        ->label(__('admin.labels.player_hp'))
                                        ->numeric(),
                                    TextInput::make('side_b_max_hp')
                                        ->label(__('admin.labels.player_max_hp'))
                                        ->numeric(),
                                    TextInput::make('side_b_stamina')
                                        ->label(__('admin.labels.player_stamina'))
                                        ->numeric(),
                                    TextInput::make('side_b_max_stamina')
                                        ->label(__('admin.labels.player_max_stamina'))
                                        ->numeric(),
                                ])
                                    ->columns(2),
                            ]),
                        ])
                            ->from('md')
                            ->verticalAlignment(VerticalAlignment::Start)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('admin.sections.fight_log'))
                    ->icon(Heroicon::OutlinedQueueList)
                    ->columns(1)
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        TextEntry::make('log_view')
                            ->hiddenLabel()
                            ->html()
                            ->state(function (?PvpDuel $record): string {
                                if (! $record instanceof PvpDuel) {
                                    return '—';
                                }

                                $log = self::logLines($record);

                                if ($log === []) {
                                    return '—';
                                }

                                $lines = [];

                                foreach ($log as $line) {
                                    if ($line !== '') {
                                        $lines[] = str($line)->sanitizeHtml()->toString();
                                    }
                                }

                                if ($lines === []) {
                                    return '—';
                                }

                                return '<div class="fi-prose">' . implode('<br>', $lines) . '</div>';
                            }),
                    ]),
            ]);
    }

    /**
     * @return list<string>
     */
    public static function logLines(PvpDuel $duel): array
    {
        if (is_array($duel->log) && $duel->log !== []) {
            return $duel->log;
        }

        $fight = self::sideFight($duel, $duel->a_tg_id);

        if (! $fight instanceof Fight) {
            $fight = self::sideFight($duel, $duel->b_tg_id);
        }

        if (! $fight instanceof Fight) {
            return [];
        }

        return $fight->log;
    }

    public static function sideFight(PvpDuel $duel, int $tgId): ?Fight
    {
        foreach ($duel->fights as $fight) {
            if ($fight->tg_id === $tgId) {
                return $fight;
            }
        }

        return null;
    }

    public static function sideUsername(?string $username, int $tgId): string
    {
        if (is_string($username) && $username !== '') {
            return $username;
        }

        return (string) $tgId;
    }
}

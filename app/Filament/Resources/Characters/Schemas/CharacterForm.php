<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\Schemas;

use App\Actions\Character\CharacterResetStatsAction;
use App\Models\Character;
use App\Services\Character\CharacterService;
use App\Services\Game\GameConfig;
use App\Support\Character\NickValidator;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

final class CharacterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('admin.sections.character_identity'))
                    ->icon(Heroicon::OutlinedIdentification)
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Group::make([
                            Group::make([
                                TextInput::make('username')
                                    ->label(__('admin.labels.username'))
                                    ->maxLength(255)
                                    ->dehydrateStateUsing(function (mixed $state): ?string {
                                        if (! is_string($state)) {
                                            return null;
                                        }

                                        $nick = mb_trim($state);

                                        if ($nick === '') {
                                            return null;
                                        }

                                        return $nick;
                                    })
                                    ->rule(function (?Character $record): Closure {
                                        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                            if (! is_string($value)) {
                                                return;
                                            }

                                            $nick = mb_trim($value);

                                            if ($nick === '') {
                                                return;
                                            }

                                            $error = app(NickValidator::class)->validate($nick);

                                            if ($error !== null) {
                                                $fail($error);

                                                return;
                                            }

                                            if (! $record instanceof Character) {
                                                return;
                                            }

                                            if (app(CharacterService::class)->usernameTakenByOther($nick, $record->tg_id)) {
                                                $fail(__('errors.nick_taken'));
                                            }
                                        };
                                    }),
                                Select::make('location')
                                    ->label(__('admin.labels.location'))
                                    ->options(self::locationOptions())
                                    ->native(false)
                                    ->searchable(),
                            ])
                                ->columns(2),
                            Group::make([
                                TextInput::make('exp')
                                    ->label(__('admin.labels.exp'))
                                    ->numeric()
                                    ->required()
                                    ->minValue(0),
                                TextInput::make('level')
                                    ->label(__('admin.labels.level'))
                                    ->numeric()
                                    ->required()
                                    ->minValue(0),
                                TextInput::make('stat_points')
                                    ->label(__('admin.labels.stat_points'))
                                    ->numeric()
                                    ->required()
                                    ->minValue(0),
                            ])
                                ->columns(4),
                            Group::make([
                                TextInput::make('strength')
                                    ->label(__('admin.labels.strength'))
                                    ->numeric()
                                    ->required()
                                    ->minValue(0),
                                TextInput::make('agility')
                                    ->label(__('admin.labels.agility'))
                                    ->numeric()
                                    ->required()
                                    ->minValue(0),
                                TextInput::make('instinct')
                                    ->label(__('admin.labels.instinct'))
                                    ->numeric()
                                    ->required()
                                    ->minValue(0),
                                TextInput::make('vitality')
                                    ->label(__('admin.labels.vitality'))
                                    ->numeric()
                                    ->required()
                                    ->minValue(0),
                            ])
                                ->columns(4),
                            Group::make([
                                TextInput::make('current_hp')
                                    ->label(__('admin.labels.current_hp'))
                                    ->numeric()
                                    ->required()
                                    ->minValue(0),
                                TextInput::make('max_hp')
                                    ->label(__('admin.labels.max_hp'))
                                    ->numeric()
                                    ->required()
                                    ->minValue(0),
                                TextInput::make('current_stamina')
                                    ->label(__('admin.labels.current_stamina'))
                                    ->numeric()
                                    ->required()
                                    ->minValue(0),
                                TextInput::make('max_stamina')
                                    ->label(__('admin.labels.max_stamina'))
                                    ->numeric()
                                    ->required()
                                    ->minValue(0),
                            ])
                                ->columns(4),
                            Actions::make([
                                Action::make('resetStats')
                                    ->label(__('admin.actions.reset_stats.label'))
                                    ->color(Color::Red)
                                    ->requiresConfirmation()
                                    ->modalHeading(__('admin.actions.reset_stats.modal_heading'))
                                    ->modalSubmitActionLabel(__('admin.actions.reset_stats.modal_submit'))
                                    ->visible(fn (?Character $record, string $operation): bool => $operation === 'edit' && $record instanceof Character && ! $record->trashed())
                                    ->action(function (Character $record, mixed $livewire): void {
                                        app(CharacterResetStatsAction::class)->handle($record);

                                        if (is_object($livewire) && method_exists($livewire, 'refreshFormData')) {
                                            $livewire->refreshFormData([
                                                'strength',
                                                'agility',
                                                'instinct',
                                                'vitality',
                                                'stat_points',
                                                'current_hp',
                                                'max_hp',
                                                'current_stamina',
                                                'max_stamina',
                                            ]);
                                        }

                                        Notification::make()
                                            ->title(__('admin.actions.reset_stats.notification'))
                                            ->success()
                                            ->send();
                                    }),
                            ])
                                ->key('characterIdentityActions')
                                ->alignment(Alignment::Start),
                        ]),
                        Group::make([
                            SpatieMediaLibraryFileUpload::make('image')
                                ->label(__('admin.labels.image'))
                                ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.image'))
                                ->collection('image')
                                ->image()
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                ->maxFiles(1),
                        ]),
                    ]),
                Section::make(__('admin.sections.character_economy'))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->columns(4)
                    ->columnSpanFull()
                    ->collapsed()
                    ->collapsible()
                    ->schema([
                        TextInput::make('silver')
                            ->label(__('admin.labels.silver'))
                            ->numeric()
                            ->required()
                            ->minValue(0),
                        TextInput::make('gold')
                            ->label(__('admin.labels.gold'))
                            ->numeric()
                            ->required()
                            ->minValue(0),
                        TextInput::make('arena_points')
                            ->label(__('admin.labels.arena_points'))
                            ->numeric()
                            ->required()
                            ->minValue(0),
                        DateTimePicker::make('premium_until')
                            ->label(__('admin.labels.premium_until'))
                            ->displayFormat('d.m.Y H:i')
                            ->seconds(false)
                            ->native(false),
                    ]),
            ]);
    }

    /**
     * @return Closure(string): (?Heroicon)
     */
    private static function fieldHintIcon(): Closure
    {
        return function (string $operation): ?Heroicon {
            if ($operation === 'view') {
                return null;
            }

            return Heroicon::OutlinedQuestionMarkCircle;
        };
    }

    /**
     * @return array<string, string>
     */
    private static function locationOptions(): array
    {
        $onboarding = app(GameConfig::class)->onboarding();

        if (! array_key_exists('cityKeys', $onboarding) || ! is_array($onboarding['cityKeys'])) {
            throw new RuntimeException('onboarding.cityKeys missing.');
        }

        $options = [];

        foreach ($onboarding['cityKeys'] as $key) {
            if (! is_string($key)) {
                throw new RuntimeException('Invalid city key.');
            }

            $name = __('onboarding.cities.' . $key);
            $options[$name] = $name;
        }

        return $options;
    }
}

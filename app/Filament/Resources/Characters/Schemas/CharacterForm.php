<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\Schemas;

use App\Actions\Character\CharacterResetStatsAction;
use App\Enums\ProgressStepEnum;
use App\Enums\Quest\OverseerTrainStepEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character;
use App\Services\CharacterService;
use App\Support\LangVariant;
use App\Support\NickValidator;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;

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
                            SpatieMediaLibraryFileUpload::make('image')
                                ->label(__('admin.labels.image'))
                                ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.image'))
                                ->collection('image')
                                ->image()
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                ->maxFiles(1),
                        ]),
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
                                                $fail(LangVariant::pick('telegram.registration.error.nick_taken'));
                                            }
                                        };
                                    }),
                                Select::make('birth_city_id')
                                    ->label(__('admin.labels.birth_city'))
                                    ->relationship('birthCity', 'name')
                                    ->native(false)
                                    ->searchable()
                                    ->preload(),
                                Select::make('city_id')
                                    ->label(__('admin.labels.city'))
                                    ->relationship('city', 'name')
                                    ->native(false)
                                    ->searchable()
                                    ->preload(),
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
                    ]),
                Section::make(__('admin.sections.character_progress'))
                    ->icon(Heroicon::OutlinedMap)
                    ->columns(1)
                    ->columnSpanFull()
                    ->collapsed()
                    ->collapsible()
                    ->extraAttributes(['class' => 'fi-character-quest-progress'])
                    ->schema([
                        Text::make(__('admin.quest_wizard.chapter'))
                            ->size(TextSize::Medium)
                            ->weight(FontWeight::SemiBold)
                            ->color(null)
                            ->columnSpanFull(),
                        Wizard::make([
                            Step::make(__('admin.quest_wizard.registration.label'))
                                ->description(__('admin.quest_wizard.registration.description'))
                                ->icon(Heroicon::OutlinedIdentification)
                                ->columns(5)
                                ->schema([
                                    Select::make('quest_phase')
                                        ->label(__('admin.labels.quest_phase'))
                                        ->options(QuestPhaseEnum::class)
                                        ->native(false)
                                        ->required()
                                        ->columnSpan(1),
                                    Select::make('progress_step')
                                        ->label(__('admin.labels.progress_step'))
                                        ->options(ProgressStepEnum::class)
                                        ->native(false)
                                        ->required()
                                        ->columnSpan(1),
                                ]),
                            Step::make(__('admin.quest_wizard.overseer.label'))
                                ->description(__('admin.quest_wizard.overseer.description'))
                                ->icon(Heroicon::OutlinedShieldCheck)
                                ->columns(5)
                                ->schema([
                                    Select::make('overseer_train_step')
                                        ->label(__('admin.labels.overseer_train_step'))
                                        ->options(OverseerTrainStepEnum::class)
                                        ->native(false)
                                        ->nullable()
                                        ->columnSpan(1),
                                    Group::make([
                                        Toggle::make('overseer_trained')->label(__('admin.labels.overseer_trained')),
                                        Toggle::make('overseer_at_tavern')->label(__('admin.labels.overseer_at_tavern')),
                                        Toggle::make('unlock_portal')->label(__('admin.labels.unlock_portal')),
                                    ])->columns(5)->columnSpanFull(),
                                ]),
                            Step::make(__('admin.quest_wizard.healer.label'))
                                ->description(__('admin.quest_wizard.healer.description'))
                                ->icon(Heroicon::OutlinedHeart)
                                ->columns(5)
                                ->schema([
                                    Toggle::make('healer_meet_open')->label(__('admin.labels.healer_meet_open')),
                                    Toggle::make('healer_at_tavern')->label(__('admin.labels.healer_at_tavern')),
                                    Toggle::make('unlock_forest')->label(__('admin.labels.unlock_forest')),
                                ]),
                            Step::make(__('admin.quest_wizard.blacksmith.label'))
                                ->description(__('admin.quest_wizard.blacksmith.description'))
                                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                                ->columns(5)
                                ->schema([
                                    Toggle::make('blacksmith_at_tavern')->label(__('admin.labels.blacksmith_at_tavern')),
                                    Toggle::make('unlock_board')->label(__('admin.labels.unlock_board')),
                                ]),
                            Step::make(__('admin.quest_wizard.buyer.label'))
                                ->description(__('admin.quest_wizard.buyer.description'))
                                ->icon(Heroicon::OutlinedShoppingBag)
                                ->columns(5)
                                ->schema([
                                    Toggle::make('buyer_at_tavern')->label(__('admin.labels.buyer_at_tavern')),
                                    Toggle::make('unlock_arena')->label(__('admin.labels.unlock_arena')),
                                ]),
                            Step::make(__('admin.quest_wizard.bruiser.label'))
                                ->description(__('admin.quest_wizard.bruiser.description'))
                                ->icon(Heroicon::OutlinedFire)
                                ->columns(5)
                                ->schema([
                                    Toggle::make('bruiser_contract_active')->label(__('admin.labels.bruiser_contract_active')),
                                    Toggle::make('bruiser_knuckle_pending')->label(__('admin.labels.bruiser_knuckle_pending')),
                                    Toggle::make('healer_debt_open')->label(__('admin.labels.healer_debt_open')),
                                    Toggle::make('healer_rat_loot_pending')->label(__('admin.labels.healer_rat_loot_pending')),
                                ]),
                            Step::make(__('admin.quest_wizard.done.label'))
                                ->description(__('admin.quest_wizard.done.description'))
                                ->icon(Heroicon::OutlinedCheckBadge)
                                ->columns(5)
                                ->schema([
                                    Toggle::make('city_unlocked_full')->label(__('admin.labels.city_unlocked_full')),
                                ]),
                        ])
                            ->skippable()
                            ->contained()
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'fi-character-quest-wizard'])
                            ->startOnStep(fn (?Character $record): int => self::questWizardStartStep($record)),
                    ]),
                Section::make(__('admin.sections.character_economy'))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->columns(1)
                    ->columnSpanFull()
                    ->collapsed()
                    ->collapsible()
                    ->schema([
                        Group::make([
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
                        ])->columns(4)->columnSpanFull(),
                        Group::make([
                            DateTimePicker::make('premium_until')
                                ->label(__('admin.labels.premium_until'))
                                ->displayFormat('d.m.Y H:i')
                                ->seconds(false)
                                ->native(false),
                            DateTimePicker::make('banned_until')
                                ->label(__('admin.labels.banned_until'))
                                ->displayFormat('d.m.Y H:i')
                                ->seconds(false)
                                ->native(false),
                        ])->columns(4)->columnSpanFull(),
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

    private static function questWizardStartStep(?Character $record): int
    {
        if (! $record instanceof Character) {
            return 1;
        }

        if (! $record->progress_step->canPlayCity()) {
            return 1;
        }

        return match ($record->quest_phase) {
            QuestPhaseEnum::Overseer => 2,
            QuestPhaseEnum::Healer => 3,
            QuestPhaseEnum::Blacksmith => 4,
            QuestPhaseEnum::Buyer => 5,
            QuestPhaseEnum::Bruiser => 6,
            QuestPhaseEnum::Done => 7,
        };
    }
}

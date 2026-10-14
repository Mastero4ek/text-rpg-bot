<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\Schemas;

use App\Actions\Character\CharacterPushQuestScreenAction;
use App\Actions\Character\CharacterResetStatsAction;
use App\Enums\Quest\QuestChapterEnum;
use App\Enums\SkinEnum;
use App\Models\Character\Character;
use App\Quests\Chapter1\Chapter1ScreenEnum;
use App\Quests\QuestChapterRegistry;
use App\Services\Character\CharacterService;
use App\Support\LangVariant;
use App\Support\NickValidator;
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
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
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
                                Select::make('skin')
                                    ->label(__('admin.labels.skin'))
                                    ->options(SkinEnum::class)
                                    ->native(false)
                                    ->nullable()
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.skin')),
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
                        ]),
                    ]),
                Section::make(__('admin.sections.character_stats'))
                    ->icon(Heroicon::OutlinedChartBar)
                    ->columns(7)
                    ->columnSpanFull()
                    ->collapsed()
                    ->collapsible()
                    ->schema([
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
                            ->columns(7)
                            ->columnSpanFull(),
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
                            ->columns(7)
                            ->columnSpanFull(),
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
                            ->columns(7)
                            ->columnSpanFull(),
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
                            ->key('characterStatsActions')
                            ->alignment(Alignment::Start)
                            ->columnSpanFull(),
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
                                ->schema(self::questScreenStepSchema(1)),
                            Step::make(__('admin.quest_wizard.overseer.label'))
                                ->description(__('admin.quest_wizard.overseer.description'))
                                ->icon(Heroicon::OutlinedShieldCheck)
                                ->columns(5)
                                ->schema(self::questScreenStepSchema(2)),
                            Step::make(__('admin.quest_wizard.healer.label'))
                                ->description(__('admin.quest_wizard.healer.description'))
                                ->icon(Heroicon::OutlinedHeart)
                                ->columns(5)
                                ->schema(self::questScreenStepSchema(3)),
                            Step::make(__('admin.quest_wizard.blacksmith.label'))
                                ->description(__('admin.quest_wizard.blacksmith.description'))
                                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                                ->columns(5)
                                ->schema(self::questScreenStepSchema(4)),
                            Step::make(__('admin.quest_wizard.buyer.label'))
                                ->description(__('admin.quest_wizard.buyer.description'))
                                ->icon(Heroicon::OutlinedShoppingBag)
                                ->columns(5)
                                ->schema(self::questScreenStepSchema(5)),
                            Step::make(__('admin.quest_wizard.bruiser.label'))
                                ->description(__('admin.quest_wizard.bruiser.description'))
                                ->icon(Heroicon::OutlinedFire)
                                ->columns(5)
                                ->schema(self::questScreenStepSchema(6)),
                            Step::make(__('admin.quest_wizard.done.label'))
                                ->description(__('admin.quest_wizard.done.description'))
                                ->icon(Heroicon::OutlinedCheckBadge)
                                ->columns(5)
                                ->schema(self::questScreenStepSchema(7)),
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
                                ->native(false)
                                ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.banned_until')),
                        ])->columns(4)->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return list<\Filament\Schemas\Components\Component>
     */
    private static function questScreenStepSchema(int $step): array
    {
        $field = 'quest_screen_' . $step;
        $module = app(QuestChapterRegistry::class)->module(QuestChapterEnum::Chapter1);

        return [
            Select::make($field)
                ->label(__('admin.labels.quest_screen'))
                ->options($module->screenOptionsForSection($step))
                ->placeholder(__('admin.quest_screens.unknown'))
                ->native(false)
                ->live()
                ->saved(false)
                ->dehydrated(false)
                ->disabled(fn (string $operation): bool => $operation === 'view')
                ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.quest_screen'))
                ->afterStateHydrated(function (Select $component, ?Character $record) use ($step, $module): void {
                    if (! $record instanceof Character) {
                        return;
                    }

                    $current = $module->detect($record);

                    if ($current instanceof Chapter1ScreenEnum && $current->section() === $step) {
                        $component->state($current->value);

                        return;
                    }

                    $component->state(null);
                })
                ->columnSpan(2),
            Actions::make([
                Action::make('applyQuestScreen_' . $step)
                    ->label(__('admin.actions.apply_quest_screen.label'))
                    ->color(Color::Teal)
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.actions.apply_quest_screen.modal_heading'))
                    ->modalDescription(__('admin.actions.apply_quest_screen.modal_description'))
                    ->modalSubmitActionLabel(__('admin.actions.apply_quest_screen.modal_submit'))
                    ->visible(fn (?Character $record, string $operation): bool => $operation === 'edit' && $record instanceof Character && ! $record->trashed())
                    ->disabled(fn (Get $get): bool => ! is_string($get($field)) || $get($field) === '')
                    ->action(function (Character $record, mixed $livewire) use ($field, $step): void {
                        $screen = self::questScreenFromLivewire($livewire, $field, $step);

                        if (! $screen instanceof Chapter1ScreenEnum) {
                            Notification::make()
                                ->title(__('admin.actions.apply_quest_screen.error.invalid'))
                                ->danger()
                                ->send();

                            return;
                        }

                        $result = app(CharacterPushQuestScreenAction::class)->handle(
                            $record,
                            QuestChapterEnum::Chapter1,
                            $screen->value,
                        );

                        if (method_exists($livewire, 'refreshAfterQuestScreen')) {
                            $livewire->refreshAfterQuestScreen();
                        }

                        if (! $result->ok) {
                            $error = $result->error;

                            if (! is_string($error) || $error === '') {
                                throw new RuntimeException('Quest screen apply failed without error.');
                            }

                            Notification::make()
                                ->title($error)
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('admin.actions.apply_quest_screen.notification'))
                            ->success()
                            ->send();
                    }),
            ])
                ->key('characterQuestScreenActions_' . $step)
                ->alignment(Alignment::Start)
                ->columnSpanFull(),
        ];
    }

    private static function questScreenFromLivewire(mixed $livewire, string $field, int $step): ?Chapter1ScreenEnum
    {
        if (! is_object($livewire) || ! property_exists($livewire, 'data') || ! is_array($livewire->data)) {
            throw new RuntimeException('Livewire form data missing for quest screen.');
        }

        if (! array_key_exists($field, $livewire->data) || ! is_string($livewire->data[$field]) || $livewire->data[$field] === '') {
            return null;
        }

        $screen = Chapter1ScreenEnum::tryFrom($livewire->data[$field]);

        if (! $screen instanceof Chapter1ScreenEnum || $screen->section() !== $step) {
            return null;
        }

        return $screen;
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

        $detected = app(QuestChapterRegistry::class)
            ->module(QuestChapterEnum::Chapter1)
            ->detect($record);

        if ($detected instanceof Chapter1ScreenEnum) {
            return $detected->section();
        }

        return 1;
    }
}

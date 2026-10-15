<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Character\Character;
use App\Models\Enemy\EnemyCatalog;
use App\Models\Fight;
use App\Models\Pvp\PvpDuel;
use Closure;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Component as LivewireComponent;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class FightPortraitUpload
{
    public static function forDuelSideA(): Group
    {
        return self::forCharacterSide(
            'side_a_image',
            fn (PvpDuel $duel): ?Character => $duel->sideA,
        );
    }

    public static function forDuelSideB(): Group
    {
        return self::forCharacterSide(
            'side_b_image',
            fn (PvpDuel $duel): ?Character => $duel->sideB,
        );
    }

    public static function forFightCharacter(): Group
    {
        return self::forCharacterSide(
            'character_image',
            fn (Fight $fight): ?Character => $fight->character,
        );
    }

    public static function forFightEnemy(): Group
    {
        return Group::make([
            self::spatieUpload(
                'enemy_image',
                function (SpatieMediaLibraryFileUpload $component): ?HasMedia {
                    $fight = self::pageRecord($component);

                    if (! $fight instanceof Fight) {
                        return null;
                    }

                    return self::enemyMediaModel($fight);
                },
            )->visible(function (LivewireComponent $livewire): bool {
                $fight = self::pageRecordFromLivewire($livewire);

                if (! $fight instanceof Fight) {
                    return false;
                }

                $model = self::enemyMediaModel($fight);

                if ($model instanceof Character) {
                    return $model->hasMedia('image');
                }

                return $model instanceof EnemyCatalog;
            }),
            Placeholder::make('enemy_image_skin')
                ->label(__('admin.labels.image'))
                ->visible(function (LivewireComponent $livewire): bool {
                    $fight = self::pageRecordFromLivewire($livewire);

                    if (! $fight instanceof Fight) {
                        return false;
                    }

                    $model = self::enemyMediaModel($fight);

                    if (! $model instanceof Character || $model->hasMedia('image')) {
                        return false;
                    }

                    $url = CharacterSkinArt::url($model->skin);

                    return is_string($url) && $url !== '';
                })
                ->content(function (LivewireComponent $livewire): HtmlString {
                    $fight = self::pageRecordFromLivewire($livewire);

                    if (! $fight instanceof Fight) {
                        return new HtmlString('—');
                    }

                    $model = self::enemyMediaModel($fight);

                    if (! $model instanceof Character) {
                        return new HtmlString('—');
                    }

                    return self::skinImageHtml($model);
                }),
        ]);
    }

    /**
     * @param  Closure(PvpDuel|Fight): (?Character)  $character
     */
    private static function forCharacterSide(string $name, Closure $character): Group
    {
        return Group::make([
            self::spatieUpload(
                $name,
                function (SpatieMediaLibraryFileUpload $component) use ($character): ?HasMedia {
                    $record = self::pageRecord($component);

                    if ($record === null) {
                        return null;
                    }

                    $side = $character($record);

                    return $side instanceof Character ? $side : null;
                },
            )->visible(function (LivewireComponent $livewire) use ($character): bool {
                $record = self::pageRecordFromLivewire($livewire);

                if ($record === null) {
                    return false;
                }

                $side = $character($record);

                return $side instanceof Character && $side->hasMedia('image');
            }),
            Placeholder::make($name . '_skin')
                ->label(__('admin.labels.image'))
                ->visible(function (LivewireComponent $livewire) use ($character): bool {
                    $record = self::pageRecordFromLivewire($livewire);

                    if ($record === null) {
                        return false;
                    }

                    $side = $character($record);

                    if (! $side instanceof Character || $side->hasMedia('image')) {
                        return false;
                    }

                    $url = CharacterSkinArt::url($side->skin);

                    return is_string($url) && $url !== '';
                })
                ->content(function (LivewireComponent $livewire) use ($character): HtmlString {
                    $record = self::pageRecordFromLivewire($livewire);

                    if ($record === null) {
                        return new HtmlString('—');
                    }

                    $side = $character($record);

                    if (! $side instanceof Character) {
                        return new HtmlString('—');
                    }

                    return self::skinImageHtml($side);
                }),
        ]);
    }

    private static function enemyMediaModel(Fight $fight): Character|EnemyCatalog|null
    {
        $enemy = $fight->enemy;

        if (! array_key_exists('catalogId', $enemy) || ! is_string($enemy['catalogId']) || $enemy['catalogId'] === '') {
            return null;
        }

        $catalogId = $enemy['catalogId'];

        if (str_starts_with($catalogId, 'pvp:')) {
            $tgId = (int) mb_substr($catalogId, 4);

            if ($tgId < 1) {
                return null;
            }

            return Character::query()->with('media')->find($tgId);
        }

        return EnemyCatalog::query()->with('media')->find($catalogId);
    }

    private static function pageRecord(Component $component): PvpDuel|Fight|null
    {
        $record = $component->getContainer()->getRecord();

        if ($record instanceof PvpDuel || $record instanceof Fight) {
            return $record;
        }

        return null;
    }

    private static function pageRecordFromLivewire(LivewireComponent $livewire): PvpDuel|Fight|null
    {
        if (! method_exists($livewire, 'getRecord')) {
            return null;
        }

        $record = $livewire->getRecord();

        if ($record instanceof PvpDuel || $record instanceof Fight) {
            return $record;
        }

        return null;
    }

    private static function skinImageHtml(Character $character): HtmlString
    {
        $url = CharacterSkinArt::url($character->skin);

        if (! is_string($url) || $url === '') {
            return new HtmlString('—');
        }

        return new HtmlString(
            '<img src="' . e($url) . '" alt="" style="max-height:16rem;max-width:100%;width:auto;border-radius:0.5rem;object-fit:contain;" />',
        );
    }

    /**
     * @param  Closure(SpatieMediaLibraryFileUpload): (HasMedia|null)  $model
     */
    private static function spatieUpload(string $name, Closure $model): SpatieMediaLibraryFileUpload
    {
        return SpatieMediaLibraryFileUpload::make($name)
            ->label(__('admin.labels.image'))
            ->collection('image')
            ->image()
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxFiles(1)
            ->dehydrated(false)
            ->model($model)
            ->loadStateFromRelationshipsUsing(static function (SpatieMediaLibraryFileUpload $component): void {
                $record = $component->getRecord();

                if (! $record instanceof HasMedia) {
                    return;
                }

                /** @var Model&HasMedia $record */
                $media = $record->load('media')->getMedia($component->getCollection() ?? 'default')
                    ->when(
                        $component->hasMediaFilter(),
                        fn (Collection $media): Collection => $component->filterMedia($media),
                    )
                    ->unless(
                        $component->isMultiple(),
                        fn (Collection $media): Collection => $media->take(1),
                    )
                    ->mapWithKeys(function (Media $media): array {
                        $uuid = $media->getAttributeValue('uuid');

                        return [$uuid => $uuid];
                    })
                    ->toArray();

                $component->rawState($media);
            });
    }
}

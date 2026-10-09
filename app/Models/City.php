<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Backpack\BackpackCatalog;
use App\Models\Bag\BagCatalog;
use App\Models\Enemy\EnemyCatalog;
use Carbon\CarbonInterface;
use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property bool $enabled
 * @property int $characters_max_rows
 * @property int $portal_cost_silver
 * @property bool $has_blacksmith
 * @property bool $has_healer
 * @property bool $has_buyer
 * @property bool $has_quest_board
 * @property bool $has_portal
 * @property bool $has_forest
 * @property bool $has_fights_list
 * @property bool $has_training_room
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
#[Fillable([
    'key',
    'name',
    'description',
    'enabled',
    'characters_max_rows',
    'portal_cost_silver',
    'has_blacksmith',
    'has_healer',
    'has_buyer',
    'has_quest_board',
    'has_portal',
    'has_forest',
    'has_fights_list',
    'has_training_room',
])]
final class City extends Model implements HasMedia
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use SoftDeletes;

    public const int DEFAULT_CHARACTERS_MAX_ROWS = 100;

    public const string KEY_ANKRAT = 'ankrat';

    public const string KEY_ELDWOOD = 'eldwood';

    public const string KEY_THORNBREAK = 'thornbreak';

    protected static string $factory = CityFactory::class;

    public static function nextKeyForName(string $name): string
    {
        $base = mb_strtolower(Str::slug($name, '_'));

        if ($base === '') {
            $base = 'city';
        }

        if (preg_match('/^[a-z]/', $base) !== 1) {
            $base = 'city_' . $base;
        }

        if (! self::query()->withTrashed()->where('key', $base)->exists()) {
            return $base;
        }

        $pattern = '/^' . preg_quote($base, '/') . '_(\d+)$/';
        $max = 0;

        foreach (self::query()->withTrashed()->where('key', 'like', $base . '%')->pluck('key') as $key) {
            if (! is_string($key)) {
                continue;
            }

            if (preg_match($pattern, $key, $matches) !== 1) {
                continue;
            }

            $n = (int) $matches[1];

            if ($n > $max) {
                $max = $n;
            }
        }

        return $base . '_' . ($max + 1);
    }

    /**
     * @return BelongsToMany<BackpackCatalog, $this>
     */
    public function backpackCatalog(): BelongsToMany
    {
        return $this->belongsToMany(
            BackpackCatalog::class,
            'city_backpack_catalog',
            'city_id',
            'backpack_catalog_id',
        );
    }

    /**
     * @return BelongsToMany<BagCatalog, $this>
     */
    public function bagCatalog(): BelongsToMany
    {
        return $this->belongsToMany(
            BagCatalog::class,
            'city_bag_catalog',
            'city_id',
            'bag_catalog_id',
        );
    }

    /**
     * @return HasMany<Character, $this>
     */
    public function birthCharacters(): HasMany
    {
        return $this->hasMany(Character::class, 'birth_city_id');
    }

    /**
     * @return HasMany<Character, $this>
     */
    public function characters(): HasMany
    {
        return $this->hasMany(Character::class, 'city_id');
    }

    /**
     * @return BelongsToMany<EnemyCatalog, $this>
     */
    public function enemyCatalog(): BelongsToMany
    {
        return $this->belongsToMany(
            EnemyCatalog::class,
            'city_enemy_catalog',
            'city_id',
            'enemy_catalog_id',
        );
    }

    /**
     * @return BelongsToMany<EnemyCatalog, $this>
     */
    public function trainingEnemyCatalog(): BelongsToMany
    {
        return $this->belongsToMany(
            EnemyCatalog::class,
            'city_training_enemy_catalog',
            'city_id',
            'enemy_catalog_id',
        );
    }

    public function isReferencedByCharacters(): bool
    {
        return $this->referencingCharactersCount() > 0;
    }

    public function referencingCharactersCount(): int
    {
        return Character::withTrashed()
            ->where(function (Builder $query): void {
                $query->where('city_id', $this->id)
                    ->orWhere('birth_city_id', $this->id);
            })
            ->count();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    protected static function booted(): void
    {
        self::saving(function (City $city): void {
            foreach ([
                'enabled',
                'has_blacksmith',
                'has_healer',
                'has_buyer',
                'has_quest_board',
                'has_portal',
                'has_forest',
                'has_fights_list',
                'has_training_room',
            ] as $flag) {
                $raw = $city->getAttributes()[$flag] ?? null;

                if ($raw === null) {
                    $city->{$flag} = false;
                }
            }

            $cost = $city->getAttributes()['portal_cost_silver'] ?? null;

            if ($cost === null) {
                $city->portal_cost_silver = 0;
            }

            $maxRows = $city->getAttributes()['characters_max_rows'] ?? null;

            if ($maxRows === null) {
                $city->characters_max_rows = self::DEFAULT_CHARACTERS_MAX_ROWS;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'characters_max_rows' => 'integer',
            'portal_cost_silver' => 'integer',
            'has_blacksmith' => 'boolean',
            'has_healer' => 'boolean',
            'has_buyer' => 'boolean',
            'has_quest_board' => 'boolean',
            'has_portal' => 'boolean',
            'has_forest' => 'boolean',
            'has_fights_list' => 'boolean',
            'has_training_room' => 'boolean',
        ];
    }
}

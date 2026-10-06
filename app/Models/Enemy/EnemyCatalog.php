<?php

declare(strict_types=1);

namespace App\Models\Enemy;

use App\Enums\Enemy\EnemyKindEnum;
use Carbon\CarbonInterface;
use Database\Factories\EnemyCatalogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property string $catalog_id
 * @property EnemyKindEnum $kind
 * @property string $name
 * @property string|null $description
 * @property bool $enabled
 * @property bool $in_fight_menu
 * @property int|null $level
 * @property int|null $strength
 * @property int|null $agility
 * @property int|null $instinct
 * @property int|null $vitality
 * @property int|null $max_hp
 * @property int|null $weapon_damage
 * @property int|null $mf_dodge
 * @property int|null $mf_anti_dodge
 * @property int|null $mf_crit
 * @property int|null $mf_anti_crit
 * @property int|null $power_pct
 * @property int $reward_exp_pct
 * @property int $reward_silver_min
 * @property int $reward_silver_max
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read Collection<int, EnemyDrop> $drops
 */
#[Fillable([
    'catalog_id',
    'kind',
    'name',
    'description',
    'enabled',
    'in_fight_menu',
    'level',
    'strength',
    'agility',
    'instinct',
    'vitality',
    'max_hp',
    'weapon_damage',
    'mf_dodge',
    'mf_anti_dodge',
    'mf_crit',
    'mf_anti_crit',
    'power_pct',
    'reward_exp_pct',
    'reward_silver_min',
    'reward_silver_max',
    'sort_order',
])]
final class EnemyCatalog extends Model implements HasMedia
{
    /** @use HasFactory<EnemyCatalogFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use SoftDeletes;

    public const TUTORIAL_CATALOG_ID = 'wooden_soldier';

    public $incrementing = false;

    protected static string $factory = EnemyCatalogFactory::class;

    protected $primaryKey = 'catalog_id';

    protected $keyType = 'string';

    protected $table = 'enemy_catalog';

    public static function nextCatalogIdForKind(EnemyKindEnum $kind): string
    {
        $prefix = mb_strtolower($kind->value);
        $pattern = '/^' . preg_quote($prefix, '/') . '_(\d+)$/';
        $max = -1;

        $ids = self::query()
            ->withTrashed()
            ->where('catalog_id', 'like', $prefix . '_%')
            ->pluck('catalog_id');

        foreach ($ids as $catalogId) {
            if (! is_string($catalogId)) {
                continue;
            }

            if (preg_match($pattern, $catalogId, $matches) !== 1) {
                continue;
            }

            $n = (int) $matches[1];

            if ($n > $max) {
                $max = $n;
            }
        }

        return $prefix . '_' . ($max + 1);
    }

    public function isTutorial(): bool
    {
        return $this->catalog_id === self::TUTORIAL_CATALOG_ID;
    }

    /**
     * @return HasMany<EnemyDrop, $this>
     */
    public function drops(): HasMany
    {
        return $this->hasMany(EnemyDrop::class, 'enemy_catalog_id', 'catalog_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    protected static function booted(): void
    {
        self::saving(function (EnemyCatalog $catalog): void {
            if ($catalog->kind === EnemyKindEnum::MIRROR) {
                $catalog->level = null;
                $catalog->strength = null;
                $catalog->agility = null;
                $catalog->instinct = null;
                $catalog->vitality = null;
                $catalog->max_hp = null;
                $catalog->weapon_damage = null;
                $catalog->mf_dodge = null;
                $catalog->mf_anti_dodge = null;
                $catalog->mf_crit = null;
                $catalog->mf_anti_crit = null;

                if ($catalog->power_pct === null) {
                    throw new RuntimeException('MIRROR power_pct is required.');
                }

                return;
            }

            $catalog->power_pct = null;

            foreach (['level', 'strength', 'agility', 'instinct', 'vitality', 'max_hp', 'weapon_damage', 'mf_dodge', 'mf_anti_dodge', 'mf_crit', 'mf_anti_crit'] as $attribute) {
                $attributes = $catalog->getAttributes();

                if (! array_key_exists($attribute, $attributes) || $attributes[$attribute] === null) {
                    $catalog->{$attribute} = 0;
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => EnemyKindEnum::class,
            'enabled' => 'boolean',
            'in_fight_menu' => 'boolean',
            'level' => 'integer',
            'strength' => 'integer',
            'agility' => 'integer',
            'instinct' => 'integer',
            'vitality' => 'integer',
            'max_hp' => 'integer',
            'weapon_damage' => 'integer',
            'mf_dodge' => 'integer',
            'mf_anti_dodge' => 'integer',
            'mf_crit' => 'integer',
            'mf_anti_crit' => 'integer',
            'power_pct' => 'integer',
            'reward_exp_pct' => 'integer',
            'reward_silver_min' => 'integer',
            'reward_silver_max' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}

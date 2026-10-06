<?php

declare(strict_types=1);

namespace App\Models\Bag;

use App\Enums\Bag\BagKindEnum;
use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Gem\GemTypeEnum;
use App\Services\Bag\BagCatalog as BagCatalogService;
use App\Support\Gem\GemDef;
use App\Support\Mf;
use App\Support\PotionDef;
use Carbon\CarbonInterface;
use Database\Factories\BagCatalogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property string $catalog_id
 * @property BagKindEnum $kind
 * @property string $name
 * @property string|null $description
 * @property bool $in_shop
 * @property bool $enabled
 * @property int $price
 * @property CurrencyEnum $currency
 * @property ProfileEnum|null $profile
 * @property int|null $effect_value
 * @property GemTypeEnum|null $type
 * @property int|null $max_durability
 * @property int $mf_dodge
 * @property int $mf_anti_dodge
 * @property int $mf_crit
 * @property int $mf_anti_crit
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
#[Fillable([
    'catalog_id',
    'kind',
    'name',
    'description',
    'in_shop',
    'enabled',
    'price',
    'currency',
    'profile',
    'effect_value',
    'type',
    'max_durability',
    'mf_dodge',
    'mf_anti_dodge',
    'mf_crit',
    'mf_anti_crit',
    'sort_order',
])]
final class BagCatalog extends Model implements HasMedia
{
    /** @use HasFactory<BagCatalogFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use SoftDeletes;

    public $incrementing = false;

    protected static string $factory = BagCatalogFactory::class;

    protected $primaryKey = 'catalog_id';

    protected $keyType = 'string';

    protected $table = 'bag_catalog';

    public static function nextCatalogIdForType(GemTypeEnum $type): string
    {
        return self::nextCatalogIdForPrefix(mb_strtolower($type->value));
    }

    public static function nextCatalogIdForProfile(ProfileEnum $profile): string
    {
        return self::nextCatalogIdForPrefix(mb_strtolower($profile->value));
    }

    public function isReferenced(): bool
    {
        return BagItem::query()
            ->where('catalog_id', $this->catalog_id)
            ->exists();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function toGemDef(): GemDef
    {
        if ($this->kind !== BagKindEnum::GEM) {
            throw new RuntimeException("Bag catalog {$this->catalog_id} is not a gem.");
        }

        if (! $this->type instanceof GemTypeEnum) {
            throw new RuntimeException("Gem {$this->catalog_id} has no type.");
        }

        if ($this->max_durability === null) {
            throw new RuntimeException("Gem {$this->catalog_id} has no durability.");
        }

        return new GemDef(
            $this->catalog_id,
            $this->type,
            $this->name,
            $this->description,
            $this->price,
            $this->currency,
            $this->in_shop,
            $this->enabled,
            $this->max_durability,
            new Mf(
                $this->mf_dodge,
                $this->mf_anti_dodge,
                $this->mf_crit,
                $this->mf_anti_crit,
            ),
        );
    }

    public function toPotionDef(): PotionDef
    {
        if ($this->kind !== BagKindEnum::POTION) {
            throw new RuntimeException("Bag catalog {$this->catalog_id} is not a potion.");
        }

        if (! $this->profile instanceof ProfileEnum) {
            throw new RuntimeException("Potion {$this->catalog_id} has no profile.");
        }

        if ($this->effect_value === null) {
            throw new RuntimeException("Potion {$this->catalog_id} has no effect_value.");
        }

        return new PotionDef(
            $this->catalog_id,
            $this->name,
            $this->description,
            $this->profile,
            $this->effect_value,
            $this->price,
            $this->currency,
            $this->in_shop,
            $this->enabled,
        );
    }

    protected static function booted(): void
    {
        self::saving(function (BagCatalog $catalog): void {
            foreach (['price', 'mf_dodge', 'mf_anti_dodge', 'mf_crit', 'mf_anti_crit'] as $attribute) {
                $raw = $catalog->getAttributes()[$attribute] ?? null;

                if ($raw === null) {
                    $catalog->{$attribute} = 0;
                }
            }

            if ($catalog->kind === BagKindEnum::GEM) {
                $catalog->profile = null;
                $catalog->effect_value = null;

                if (! $catalog->type instanceof GemTypeEnum) {
                    throw new RuntimeException('Gem type is required.');
                }

                $column = $catalog->type->mfColumn();
                $kept = (int) ($catalog->getAttributes()[$column] ?? 0);

                if ($kept < 1) {
                    throw new RuntimeException('Gem type MF must be >= 1.');
                }

                $catalog->mf_dodge = 0;
                $catalog->mf_anti_dodge = 0;
                $catalog->mf_crit = 0;
                $catalog->mf_anti_crit = 0;
                $catalog->{$column} = $kept;

                if ($catalog->max_durability === null || $catalog->max_durability < 1) {
                    throw new RuntimeException('Gem max_durability must be >= 1.');
                }

                return;
            }

            $catalog->type = null;
            $catalog->max_durability = null;
            $catalog->mf_dodge = 0;
            $catalog->mf_anti_dodge = 0;
            $catalog->mf_crit = 0;
            $catalog->mf_anti_crit = 0;
        });

        self::saved(function (): void {
            app(BagCatalogService::class)->forgetCache();
        });

        self::deleted(function (): void {
            app(BagCatalogService::class)->forgetCache();
        });

        self::restored(function (): void {
            app(BagCatalogService::class)->forgetCache();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => BagKindEnum::class,
            'in_shop' => 'boolean',
            'enabled' => 'boolean',
            'price' => 'integer',
            'currency' => CurrencyEnum::class,
            'profile' => ProfileEnum::class,
            'effect_value' => 'integer',
            'type' => GemTypeEnum::class,
            'max_durability' => 'integer',
            'mf_dodge' => 'integer',
            'mf_anti_dodge' => 'integer',
            'mf_crit' => 'integer',
            'mf_anti_crit' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    private static function nextCatalogIdForPrefix(string $prefix): string
    {
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
}

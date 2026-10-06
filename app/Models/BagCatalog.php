<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Economy\CurrencyEnum;
use App\Enums\Gem\GemTypeEnum;
use App\Services\Gem\GemCatalog;
use App\Support\Game\Mf;
use App\Support\Gem\GemDef;
use Carbon\CarbonInterface;
use Database\Factories\GemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property string $gem_id
 * @property string $name
 * @property string|null $description
 * @property GemTypeEnum $type
 * @property bool $in_shop
 * @property bool $enabled
 * @property int $price
 * @property CurrencyEnum $currency
 * @property int $max_durability
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
    'gem_id',
    'name',
    'description',
    'type',
    'in_shop',
    'enabled',
    'price',
    'currency',
    'max_durability',
    'mf_dodge',
    'mf_anti_dodge',
    'mf_crit',
    'mf_anti_crit',
    'sort_order',
])]
final class Gem extends Model implements HasMedia
{
    /** @use HasFactory<GemFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use SoftDeletes;

    public $incrementing = false;

    protected $primaryKey = 'gem_id';

    protected $keyType = 'string';

    protected $table = 'gems';

    public static function nextGemIdForType(GemTypeEnum $type): string
    {
        $prefix = mb_strtolower($type->value);
        $pattern = '/^' . preg_quote($prefix, '/') . '_(\d+)$/';
        $max = -1;

        $ids = self::query()
            ->withTrashed()
            ->where('gem_id', 'like', $prefix . '_%')
            ->pluck('gem_id');

        foreach ($ids as $gemId) {
            if (! is_string($gemId)) {
                continue;
            }

            if (preg_match($pattern, $gemId, $matches) !== 1) {
                continue;
            }

            $n = (int) $matches[1];

            if ($n > $max) {
                $max = $n;
            }
        }

        return $prefix . '_' . ($max + 1);
    }

    public function isReferenced(): bool
    {
        $needle = '%"gem_id":"' . $this->gem_id . '"%';

        if (
            Character::query()
                ->where('gem_pouch', 'like', $needle)
                ->exists()
        ) {
            return true;
        }

        return Inventory::query()
            ->where('socketed_gems', 'like', $needle)
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
        return new GemDef(
            $this->gem_id,
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

    protected static function booted(): void
    {
        self::saving(function (Gem $gem): void {
            foreach (['price', 'max_durability', 'mf_dodge', 'mf_anti_dodge', 'mf_crit', 'mf_anti_crit'] as $attribute) {
                $raw = $gem->getAttributes()[$attribute] ?? null;

                if ($raw === null) {
                    $gem->{$attribute} = 0;
                }
            }

            $column = $gem->type->mfColumn();
            $kept = (int) ($gem->getAttributes()[$column] ?? 0);

            if ($kept < 1) {
                throw new RuntimeException('Gem type MF must be >= 1.');
            }

            $gem->mf_dodge = 0;
            $gem->mf_anti_dodge = 0;
            $gem->mf_crit = 0;
            $gem->mf_anti_crit = 0;
            $gem->{$column} = $kept;
        });

        self::saved(function (): void {
            app(GemCatalog::class)->forgetCache();
        });

        self::deleted(function (): void {
            app(GemCatalog::class)->forgetCache();
        });

        self::restored(function (): void {
            app(GemCatalog::class)->forgetCache();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => GemTypeEnum::class,
            'in_shop' => 'boolean',
            'enabled' => 'boolean',
            'price' => 'integer',
            'currency' => CurrencyEnum::class,
            'max_durability' => 'integer',
            'mf_dodge' => 'integer',
            'mf_anti_dodge' => 'integer',
            'mf_crit' => 'integer',
            'mf_anti_crit' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}

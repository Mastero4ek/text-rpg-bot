<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Backpack\BackpackCatalogSyncNamesAction;
use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Equipment\RepairEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Services\Shop\ShopCatalog;
use App\Support\Equipment\EquipmentDef;
use App\Support\Game\Mf;
use Carbon\CarbonInterface;
use Database\Factories\BackpackCatalogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property string $catalog_id
 * @property string $name
 * @property string|null $description
 * @property TypeEnum $item_type
 * @property SlotEnum|null $slot
 * @property ProfileEnum|null $profile
 * @property bool $in_shop
 * @property bool $enabled
 * @property int $price
 * @property CurrencyEnum $currency
 * @property RepairEnum $repair_tier
 * @property int $weapon_damage_min
 * @property int $weapon_damage_max
 * @property int $stat_bonus
 * @property int $armor
 * @property int $mf_dodge
 * @property int $mf_anti_dodge
 * @property int $mf_crit
 * @property int $mf_anti_crit
 * @property int|null $req_strength
 * @property int|null $req_agility
 * @property int|null $req_instinct
 * @property int|null $req_vitality
 * @property int|null $req_level
 * @property int|null $max_durability
 * @property int|null $durability_loss_per_fight
 * @property bool $repairable
 * @property int|null $gem_slots
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
#[Fillable([
    'catalog_id',
    'name',
    'description',
    'item_type',
    'slot',
    'profile',
    'in_shop',
    'enabled',
    'price',
    'currency',
    'repair_tier',
    'weapon_damage_min',
    'weapon_damage_max',
    'stat_bonus',
    'armor',
    'mf_dodge',
    'mf_anti_dodge',
    'mf_crit',
    'mf_anti_crit',
    'req_strength',
    'req_agility',
    'req_instinct',
    'req_vitality',
    'req_level',
    'max_durability',
    'durability_loss_per_fight',
    'repairable',
    'gem_slots',
    'sort_order',
])]
final class BackpackCatalog extends Model implements HasMedia
{
    /** @use HasFactory<BackpackCatalogFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use SoftDeletes;

    public $incrementing = false;

    protected $primaryKey = 'catalog_id';

    protected $keyType = 'string';

    protected $table = 'backpack_catalog';

    public static function nextCatalogIdForProfile(ProfileEnum $profile): string
    {
        $prefix = mb_strtolower($profile->value);
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

    public function isReferencedByBackpack(): bool
    {
        return BackpackItem::query()
            ->where('catalog_id', $this->catalog_id)
            ->exists();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function toEquipmentDef(): EquipmentDef
    {
        return new EquipmentDef(
            $this->catalog_id,
            $this->name,
            $this->description,
            $this->item_type,
            $this->slot,
            $this->profile,
            $this->price,
            $this->currency,
            $this->weapon_damage_min,
            $this->weapon_damage_max,
            new Mf(
                $this->mf_dodge,
                $this->mf_anti_dodge,
                $this->mf_crit,
                $this->mf_anti_crit,
            ),
            $this->stat_bonus,
            $this->armor,
            $this->req_level,
            $this->req_strength,
            $this->req_agility,
            $this->req_instinct,
            $this->req_vitality,
            $this->max_durability,
            $this->durability_loss_per_fight,
            $this->repairable,
            $this->repair_tier,
            $this->gem_slots,
        );
    }

    protected static function booted(): void
    {
        self::saving(function (BackpackCatalog $catalog): void {
            foreach ([
                'weapon_damage_min',
                'weapon_damage_max',
                'stat_bonus',
                'armor',
                'mf_dodge',
                'mf_anti_dodge',
                'mf_crit',
                'mf_anti_crit',
                'price',
            ] as $attribute) {
                $raw = $catalog->getAttributes()[$attribute] ?? null;

                if ($raw === null) {
                    $catalog->{$attribute} = 0;
                }
            }

            if ($catalog->weapon_damage_max < $catalog->weapon_damage_min) {
                $catalog->weapon_damage_max = $catalog->weapon_damage_min;
            }

            if (
                $catalog->slot === SlotEnum::GLOVES
                || $catalog->slot === SlotEnum::SHIELD
            ) {
                $catalog->armor = 0;
            }

            if ($catalog->item_type === TypeEnum::JEWELRY) {
                $catalog->gem_slots = null;
            }
        });

        self::saved(function (BackpackCatalog $catalog): void {
            app(ShopCatalog::class)->forgetCache();

            if ($catalog->wasChanged('name')) {
                app(BackpackCatalogSyncNamesAction::class)->handle($catalog);
            }
        });

        self::deleted(function (): void {
            app(ShopCatalog::class)->forgetCache();
        });

        self::restored(function (): void {
            app(ShopCatalog::class)->forgetCache();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item_type' => TypeEnum::class,
            'slot' => SlotEnum::class,
            'profile' => ProfileEnum::class,
            'in_shop' => 'boolean',
            'enabled' => 'boolean',
            'price' => 'integer',
            'currency' => CurrencyEnum::class,
            'repair_tier' => RepairEnum::class,
            'weapon_damage_min' => 'integer',
            'weapon_damage_max' => 'integer',
            'stat_bonus' => 'integer',
            'armor' => 'integer',
            'mf_dodge' => 'integer',
            'mf_anti_dodge' => 'integer',
            'mf_crit' => 'integer',
            'mf_anti_crit' => 'integer',
            'req_strength' => 'integer',
            'req_agility' => 'integer',
            'req_instinct' => 'integer',
            'req_vitality' => 'integer',
            'req_level' => 'integer',
            'max_durability' => 'integer',
            'durability_loss_per_fight' => 'integer',
            'repairable' => 'boolean',
            'gem_slots' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}

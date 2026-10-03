<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Equipment\EquipmentSyncInventoryNamesAction;
use App\Enums\Equipment\CurrencyEnum;
use App\Enums\Equipment\EffectTypeEnum;
use App\Enums\Equipment\EquipmentProfileEnum;
use App\Enums\Equipment\RepairTierEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Services\Shop\ShopCatalog;
use App\Support\Game\EquipmentDef;
use App\Support\Game\Mf;
use Carbon\CarbonInterface;
use Database\Factories\EquipmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property string $item_id
 * @property string $name
 * @property string|null $description
 * @property TypeEnum $item_type
 * @property SlotEnum|null $slot
 * @property EquipmentProfileEnum|null $profile
 * @property int|null $tier
 * @property bool $in_shop
 * @property bool $enabled
 * @property int $price
 * @property CurrencyEnum $currency
 * @property bool $vip_only
 * @property RepairTierEnum $repair_tier
 * @property int $weapon_damage
 * @property int $stat_bonus
 * @property int $armor
 * @property int $mf_dodge
 * @property int $mf_anti_dodge
 * @property int $mf_crit
 * @property int $mf_anti_crit
 * @property EffectTypeEnum|null $effect_type
 * @property int|null $effect_value
 * @property int|null $req_strength
 * @property int|null $req_agility
 * @property int|null $req_instinct
 * @property int|null $req_level
 * @property int|null $max_durability
 * @property int|null $durability_loss_per_fight
 * @property bool $repairable
 * @property int|null $gem_slots
 * @property list<string>|null $allowed_gem_types
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
#[Fillable([
    'item_id',
    'name',
    'description',
    'item_type',
    'slot',
    'profile',
    'tier',
    'in_shop',
    'enabled',
    'price',
    'currency',
    'vip_only',
    'repair_tier',
    'weapon_damage',
    'stat_bonus',
    'armor',
    'mf_dodge',
    'mf_anti_dodge',
    'mf_crit',
    'mf_anti_crit',
    'effect_type',
    'effect_value',
    'req_strength',
    'req_agility',
    'req_instinct',
    'req_level',
    'max_durability',
    'durability_loss_per_fight',
    'repairable',
    'gem_slots',
    'allowed_gem_types',
    'sort_order',
])]
final class Equipment extends Model implements HasMedia
{
    /** @use HasFactory<EquipmentFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use SoftDeletes;

    public $incrementing = false;

    protected $primaryKey = 'item_id';

    protected $keyType = 'string';

    protected $table = 'equipment';

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function isReferencedByInventory(): bool
    {
        return Inventory::query()
            ->where('item_id', $this->item_id)
            ->exists();
    }

    public function toEquipmentDef(): EquipmentDef
    {
        return new EquipmentDef(
            $this->item_id,
            $this->name,
            $this->item_type,
            $this->profile,
            $this->price,
            $this->currency,
            $this->weapon_damage,
            new Mf(
                $this->mf_dodge,
                $this->mf_anti_dodge,
                $this->mf_crit,
                $this->mf_anti_crit,
            ),
            $this->stat_bonus,
            $this->armor,
            $this->effect_type,
            $this->effect_value,
        );
    }

    protected static function booted(): void
    {
        self::saved(function (Equipment $equipment): void {
            app(ShopCatalog::class)->forgetCache();

            if ($equipment->wasChanged('name')) {
                app(EquipmentSyncInventoryNamesAction::class)->handle($equipment);
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
            'profile' => EquipmentProfileEnum::class,
            'tier' => 'integer',
            'in_shop' => 'boolean',
            'enabled' => 'boolean',
            'price' => 'integer',
            'currency' => CurrencyEnum::class,
            'vip_only' => 'boolean',
            'repair_tier' => RepairTierEnum::class,
            'weapon_damage' => 'integer',
            'stat_bonus' => 'integer',
            'armor' => 'integer',
            'mf_dodge' => 'integer',
            'mf_anti_dodge' => 'integer',
            'mf_crit' => 'integer',
            'mf_anti_crit' => 'integer',
            'effect_type' => EffectTypeEnum::class,
            'effect_value' => 'integer',
            'req_strength' => 'integer',
            'req_agility' => 'integer',
            'req_instinct' => 'integer',
            'req_level' => 'integer',
            'max_durability' => 'integer',
            'durability_loss_per_fight' => 'integer',
            'repairable' => 'boolean',
            'gem_slots' => 'integer',
            'allowed_gem_types' => 'array',
            'sort_order' => 'integer',
        ];
    }
}

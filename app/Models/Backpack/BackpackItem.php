<?php

declare(strict_types=1);

namespace App\Models\Backpack;

use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Bag\BagItem;
use App\Models\Character;
use App\Models\LoadoutSlot;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $tg_id
 * @property string $catalog_id
 * @property string $item_name
 * @property TypeEnum $item_type
 * @property SlotEnum|null $slot
 * @property int|null $durability
 * @property int|null $max_durability
 * @property CarbonInterface|null $created_at
 * @property-read Character|null $character
 * @property-read BackpackCatalog|null $catalog
 * @property-read LoadoutSlot|null $loadoutSlot
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BagItem> $socketedGems
 *
 * @method static Builder<static> equipped()
 * @method static Builder<static> unequipped()
 */
#[Fillable([
    'tg_id',
    'catalog_id',
    'item_name',
    'item_type',
    'slot',
    'durability',
    'max_durability',
    'created_at',
])]
final class BackpackItem extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Character, $this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'tg_id', 'tg_id');
    }

    /**
     * @return BelongsTo<BackpackCatalog, $this>
     */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(BackpackCatalog::class, 'catalog_id', 'catalog_id')->withTrashed();
    }

    public function isEquipped(): bool
    {
        if ($this->relationLoaded('loadoutSlot')) {
            return $this->getRelation('loadoutSlot') !== null;
        }

        return $this->loadoutSlot()->exists();
    }

    /**
     * @return HasOne<LoadoutSlot, $this>
     */
    public function loadoutSlot(): HasOne
    {
        return $this->hasOne(LoadoutSlot::class, 'backpack_item_id');
    }

    /**
     * @return HasMany<BagItem, $this>
     */
    public function socketedGems(): HasMany
    {
        return $this->hasMany(BagItem::class, 'backpack_item_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tg_id' => 'integer',
            'item_type' => TypeEnum::class,
            'slot' => SlotEnum::class,
            'durability' => 'integer',
            'max_durability' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeEquipped(Builder $query): Builder
    {
        return $query->whereHas('loadoutSlot');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeUnequipped(Builder $query): Builder
    {
        return $query->whereDoesntHave('loadoutSlot');
    }
}

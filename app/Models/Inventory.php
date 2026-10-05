<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $tg_id
 * @property string $item_id
 * @property string $item_name
 * @property TypeEnum $item_type
 * @property SlotEnum|null $slot
 * @property int|null $durability
 * @property int|null $max_durability
 * @property list<mixed>|null $socketed_gems
 * @property int $quantity
 * @property CarbonInterface|null $created_at
 * @property-read Character|null $character
 * @property-read Equipment|null $equipment
 * @property-read LoadoutSlot|null $loadoutSlot
 *
 * @method static Builder<static> equipped()
 * @method static Builder<static> unequipped()
 */
#[Fillable([
    'tg_id',
    'item_id',
    'item_name',
    'item_type',
    'slot',
    'durability',
    'max_durability',
    'socketed_gems',
    'quantity',
    'created_at',
])]
final class Inventory extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity' => 1,
    ];

    /**
     * @return BelongsTo<Character, $this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'tg_id', 'tg_id');
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'item_id', 'item_id')->withTrashed();
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
        return $this->hasOne(LoadoutSlot::class, 'inventory_id');
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
            'socketed_gems' => 'array',
            'quantity' => 'integer',
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

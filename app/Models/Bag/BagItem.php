<?php

declare(strict_types=1);

namespace App\Models\Bag;

use App\Enums\Bag\BagKindEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\Character;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tg_id
 * @property BagKindEnum $kind
 * @property string $catalog_id
 * @property int $quantity
 * @property int|null $durability
 * @property int|null $backpack_item_id
 * @property CarbonInterface|null $created_at
 * @property-read Character|null $character
 * @property-read BagCatalog|null $catalog
 * @property-read BackpackItem|null $host
 *
 * @method static Builder<static> loose()
 * @method static Builder<static> gems()
 * @method static Builder<static> potions()
 */
#[Fillable([
    'tg_id',
    'kind',
    'catalog_id',
    'quantity',
    'durability',
    'backpack_item_id',
    'created_at',
])]
final class BagItem extends Model
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
     * @return BelongsTo<BagCatalog, $this>
     */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(BagCatalog::class, 'catalog_id', 'catalog_id')->withTrashed();
    }

    /**
     * @return BelongsTo<BackpackItem, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(BackpackItem::class, 'backpack_item_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tg_id' => 'integer',
            'kind' => BagKindEnum::class,
            'quantity' => 'integer',
            'durability' => 'integer',
            'backpack_item_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeLoose(Builder $query): Builder
    {
        return $query->whereNull('backpack_item_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeGems(Builder $query): Builder
    {
        return $query->where('kind', BagKindEnum::GEM->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopePotions(Builder $query): Builder
    {
        return $query->where('kind', BagKindEnum::POTION->value);
    }
}

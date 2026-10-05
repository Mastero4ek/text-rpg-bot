<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Equipment\SlotEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tg_id
 * @property SlotEnum $slot
 * @property int $inventory_id
 * @property-read Character|null $character
 * @property-read Inventory|null $inventory
 */
#[Fillable([
    'tg_id',
    'slot',
    'inventory_id',
])]
final class LoadoutSlot extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<Character, $this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'tg_id', 'tg_id');
    }

    /**
     * @return BelongsTo<Inventory, $this>
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tg_id' => 'integer',
            'slot' => SlotEnum::class,
            'inventory_id' => 'integer',
        ];
    }
}

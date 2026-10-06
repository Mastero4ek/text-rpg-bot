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
 * @property int $backpack_item_id
 * @property-read Character|null $character
 * @property-read BackpackItem|null $backpackItem
 */
#[Fillable([
    'tg_id',
    'slot',
    'backpack_item_id',
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
     * @return BelongsTo<BackpackItem, $this>
     */
    public function backpackItem(): BelongsTo
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
            'slot' => SlotEnum::class,
            'backpack_item_id' => 'integer',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tg_id
 * @property string $item_id
 * @property string $item_name
 * @property TypeEnum $item_type
 * @property SlotEnum|null $slot
 * @property int|null $durability
 * @property int|null $max_durability
 * @property bool $is_equipped
 * @property list<mixed>|null $socketed_gems
 */
#[Fillable([
    'tg_id',
    'item_id',
    'item_name',
    'item_type',
    'slot',
    'durability',
    'max_durability',
    'is_equipped',
    'socketed_gems',
])]
final class Inventory extends Model
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
            'is_equipped' => 'boolean',
            'socketed_gems' => 'array',
        ];
    }
}

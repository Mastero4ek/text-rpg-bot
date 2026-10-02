<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ItemTypeEnum;
use App\Enums\WeaponClassEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tg_id
 * @property string $item_id
 * @property string $item_name
 * @property ItemTypeEnum $item_type
 * @property ItemTypeEnum|null $slot
 * @property int $stat_bonus
 * @property WeaponClassEnum|null $weapon_class
 * @property int|null $durability
 * @property int|null $max_durability
 * @property bool $is_equipped
 */
#[Fillable([
    'tg_id',
    'item_id',
    'item_name',
    'item_type',
    'slot',
    'stat_bonus',
    'weapon_class',
    'durability',
    'max_durability',
    'is_equipped',
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
            'item_type' => ItemTypeEnum::class,
            'slot' => ItemTypeEnum::class,
            'stat_bonus' => 'integer',
            'weapon_class' => WeaponClassEnum::class,
            'durability' => 'integer',
            'max_durability' => 'integer',
            'is_equipped' => 'boolean',
        ];
    }
}

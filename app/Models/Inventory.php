<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Equipment\EquipmentProfileEnum;
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
 * @property TypeEnum|null $slot
 * @property int $stat_bonus
 * @property EquipmentProfileEnum|null $profile
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
    'profile',
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
            'item_type' => TypeEnum::class,
            'slot' => TypeEnum::class,
            'stat_bonus' => 'integer',
            'profile' => EquipmentProfileEnum::class,
            'durability' => 'integer',
            'max_durability' => 'integer',
            'is_equipped' => 'boolean',
        ];
    }
}

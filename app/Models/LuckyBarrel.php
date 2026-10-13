<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LuckyBarrel\LuckyBarrelStatusEnum;
use App\Models\Character\Character;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $tg_id
 * @property LuckyBarrelStatusEnum $status
 * @property int $base_stake
 * @property int $rat_index
 * @property list<int> $opened_barrels
 * @property int $current_step
 */
#[Fillable([
    'tg_id',
    'status',
    'base_stake',
    'rat_index',
    'opened_barrels',
    'current_step',
])]
final class LuckyBarrel extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'tg_id';

    protected $keyType = 'int';

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
            'status' => LuckyBarrelStatusEnum::class,
            'base_stake' => 'integer',
            'rat_index' => 'integer',
            'opened_barrels' => 'array',
            'current_step' => 'integer',
        ];
    }
}

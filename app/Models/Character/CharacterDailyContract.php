<?php

declare(strict_types=1);

namespace App\Models\Character;

use App\Enums\DailyContract\DailyContractStatusEnum;
use App\Enums\DailyContract\DailyContractTypeEnum;
use App\Enums\NpcEnum;
use App\Models\Bag\BagCatalog;
use App\Models\City;
use App\Models\Enemy\EnemyCatalog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tg_id
 * @property int $city_id
 * @property int|null $target_city_id
 * @property Carbon $board_date
 * @property int $slot
 * @property DailyContractTypeEnum $type
 * @property DailyContractStatusEnum $status
 * @property string|null $enemy_catalog_id
 * @property string|null $bag_catalog_id
 * @property NpcEnum $issuer_npc
 * @property int $target_kills
 * @property int $reward_silver
 * @property int $reward_exp
 * @property array<string, mixed> $params
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BagCatalog|null $bagCatalog
 * @property-read Character $character
 * @property-read City $city
 * @property-read EnemyCatalog|null $enemyCatalog
 * @property-read City|null $targetCity
 */
#[Fillable([
    'tg_id',
    'city_id',
    'target_city_id',
    'board_date',
    'slot',
    'type',
    'status',
    'enemy_catalog_id',
    'bag_catalog_id',
    'issuer_npc',
    'target_kills',
    'reward_silver',
    'reward_exp',
    'params',
])]
final class CharacterDailyContract extends Model
{
    protected $table = 'character_daily_contracts';

    /**
     * @return BelongsTo<BagCatalog, $this>
     */
    public function bagCatalog(): BelongsTo
    {
        return $this->belongsTo(BagCatalog::class, 'bag_catalog_id', 'catalog_id');
    }

    /**
     * @return BelongsTo<Character, $this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'tg_id', 'tg_id');
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return BelongsTo<EnemyCatalog, $this>
     */
    public function enemyCatalog(): BelongsTo
    {
        return $this->belongsTo(EnemyCatalog::class, 'enemy_catalog_id', 'catalog_id');
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function targetCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'target_city_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tg_id' => 'integer',
            'city_id' => 'integer',
            'target_city_id' => 'integer',
            'board_date' => 'date',
            'slot' => 'integer',
            'type' => DailyContractTypeEnum::class,
            'status' => DailyContractStatusEnum::class,
            'issuer_npc' => NpcEnum::class,
            'target_kills' => 'integer',
            'reward_silver' => 'integer',
            'reward_exp' => 'integer',
            'params' => 'array',
        ];
    }
}

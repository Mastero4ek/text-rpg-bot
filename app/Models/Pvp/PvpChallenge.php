<?php

declare(strict_types=1);

namespace App\Models\Pvp;

use App\Enums\Pvp\PvpChallengeStatusEnum;
use App\Models\Character\Character;
use App\Models\City;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $challenger_tg_id
 * @property int $city_id
 * @property int $stake_silver
 * @property int $stake_gold
 * @property PvpChallengeStatusEnum $status
 * @property Carbon $expires_at
 * @property int|null $accepted_by_tg_id
 * @property int|null $duel_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Character|null $challenger
 * @property-read Character|null $acceptor
 * @property-read City|null $city
 * @property-read PvpDuel|null $duel
 */
final class PvpChallenge extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'challenger_tg_id',
        'city_id',
        'stake_silver',
        'stake_gold',
        'status',
        'expires_at',
        'accepted_by_tg_id',
        'duel_id',
    ];

    /**
     * @return BelongsTo<Character, $this>
     */
    public function challenger(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'challenger_tg_id', 'tg_id');
    }

    /**
     * @return BelongsTo<Character, $this>
     */
    public function acceptor(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'accepted_by_tg_id', 'tg_id');
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return BelongsTo<PvpDuel, $this>
     */
    public function duel(): BelongsTo
    {
        return $this->belongsTo(PvpDuel::class, 'duel_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'challenger_tg_id' => 'integer',
            'city_id' => 'integer',
            'stake_silver' => 'integer',
            'stake_gold' => 'integer',
            'status' => PvpChallengeStatusEnum::class,
            'expires_at' => 'datetime',
            'accepted_by_tg_id' => 'integer',
            'duel_id' => 'integer',
        ];
    }
}

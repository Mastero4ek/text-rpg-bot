<?php

declare(strict_types=1);

namespace App\Models\Pvp;

use App\Enums\Pvp\PvpDuelStatusEnum;
use App\Models\Character\Character;
use App\Models\Fight;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $challenge_id
 * @property int $a_tg_id
 * @property int $b_tg_id
 * @property int $round
 * @property PvpDuelStatusEnum $status
 * @property int|null $winner_tg_id
 * @property array<string, mixed>|null $a_choice
 * @property array<string, mixed>|null $b_choice
 * @property Carbon|null $round_deadline_at
 * @property int $round_seq
 * @property int $mutual_skip_streak
 * @property list<string>|null $log
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PvpDuel extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'challenge_id',
        'a_tg_id',
        'b_tg_id',
        'round',
        'status',
        'winner_tg_id',
        'a_choice',
        'b_choice',
        'round_deadline_at',
        'round_seq',
        'mutual_skip_streak',
        'log',
    ];

    /**
     * @return BelongsTo<Character, $this>
     */
    public function sideA(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'a_tg_id', 'tg_id');
    }

    /**
     * @return BelongsTo<Character, $this>
     */
    public function sideB(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'b_tg_id', 'tg_id');
    }

    /**
     * @return BelongsTo<PvpChallenge, $this>
     */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(PvpChallenge::class, 'challenge_id');
    }

    /**
     * @return HasMany<Fight, $this>
     */
    public function fights(): HasMany
    {
        return $this->hasMany(Fight::class, 'duel_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'challenge_id' => 'integer',
            'a_tg_id' => 'integer',
            'b_tg_id' => 'integer',
            'round' => 'integer',
            'status' => PvpDuelStatusEnum::class,
            'winner_tg_id' => 'integer',
            'a_choice' => 'array',
            'b_choice' => 'array',
            'round_deadline_at' => 'datetime',
            'round_seq' => 'integer',
            'mutual_skip_streak' => 'integer',
            'log' => 'array',
        ];
    }
}

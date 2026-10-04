<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FightKindEnum;
use App\Enums\FightPlayerAttackEnum;
use App\Enums\FightStepEnum;
use App\Enums\StanceEnum;
use App\Enums\ZoneEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $tg_id
 * @property FightKindEnum $kind
 * @property bool $tutorial
 * @property int $player_hp
 * @property int $player_max_hp
 * @property array<string, mixed> $enemy
 * @property FightStepEnum $step
 * @property StanceEnum|null $player_stance
 * @property FightPlayerAttackEnum|null $player_attack
 * @property FightPlayerAttackEnum|null $player_attack_second
 * @property ZoneEnum|null $player_defend
 * @property ZoneEnum|null $player_defend_second
 * @property bool $use_potion
 * @property int $pierce_count
 * @property list<string> $log
 */
#[Fillable([
    'tg_id',
    'kind',
    'tutorial',
    'player_hp',
    'player_max_hp',
    'enemy',
    'step',
    'player_stance',
    'player_attack',
    'player_attack_second',
    'player_defend',
    'player_defend_second',
    'use_potion',
    'pierce_count',
    'log',
])]
final class Fight extends Model
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
            'kind' => FightKindEnum::class,
            'tutorial' => 'boolean',
            'player_hp' => 'integer',
            'player_max_hp' => 'integer',
            'enemy' => 'array',
            'step' => FightStepEnum::class,
            'player_stance' => StanceEnum::class,
            'player_attack' => FightPlayerAttackEnum::class,
            'player_attack_second' => FightPlayerAttackEnum::class,
            'player_defend' => ZoneEnum::class,
            'player_defend_second' => ZoneEnum::class,
            'use_potion' => 'boolean',
            'pierce_count' => 'integer',
            'log' => 'array',
        ];
    }
}

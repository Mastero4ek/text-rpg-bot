<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Combat\StanceEnum;
use App\Enums\Combat\ZoneEnum;
use App\Enums\Fight\FightKindEnum;
use App\Enums\Fight\FightReturnEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\Fight\PlayerAttackEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $tg_id
 * @property FightKindEnum $kind
 * @property bool $tutorial
 * @property bool $hall
 * @property FightReturnEnum|null $return_to
 * @property int $player_hp
 * @property int $player_max_hp
 * @property int $player_stamina
 * @property int $player_max_stamina
 * @property array<string, mixed> $enemy
 * @property FightStepEnum $step
 * @property StanceEnum|null $player_stance
 * @property PlayerAttackEnum|null $player_attack
 * @property PlayerAttackEnum|null $player_attack_second
 * @property ZoneEnum|null $player_defend
 * @property ZoneEnum|null $player_defend_second
 * @property bool $use_potion
 * @property int $pierce_count
 * @property list<string> $log
 * @property array<string, mixed>|null $last_round
 * @property Carbon|null $turn_deadline_at
 * @property int $turn_seq
 * @property int|null $tg_chat_id
 * @property int|null $tg_message_id
 * @property int|null $tg_log_message_id
 * @property string|null $tg_reply_kind
 */
#[Fillable([
    'tg_id',
    'kind',
    'tutorial',
    'hall',
    'return_to',
    'player_hp',
    'player_max_hp',
    'player_stamina',
    'player_max_stamina',
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
    'last_round',
    'turn_deadline_at',
    'turn_seq',
    'tg_chat_id',
    'tg_message_id',
    'tg_log_message_id',
    'tg_reply_kind',
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
            'hall' => 'boolean',
            'return_to' => FightReturnEnum::class,
            'player_hp' => 'integer',
            'player_max_hp' => 'integer',
            'player_stamina' => 'integer',
            'player_max_stamina' => 'integer',
            'enemy' => 'array',
            'step' => FightStepEnum::class,
            'player_stance' => StanceEnum::class,
            'player_attack' => PlayerAttackEnum::class,
            'player_attack_second' => PlayerAttackEnum::class,
            'player_defend' => ZoneEnum::class,
            'player_defend_second' => ZoneEnum::class,
            'use_potion' => 'boolean',
            'pierce_count' => 'integer',
            'log' => 'array',
            'last_round' => 'array',
            'turn_deadline_at' => 'datetime',
            'turn_seq' => 'integer',
            'tg_chat_id' => 'integer',
            'tg_message_id' => 'integer',
            'tg_log_message_id' => 'integer',
            'tg_reply_kind' => 'string',
        ];
    }
}

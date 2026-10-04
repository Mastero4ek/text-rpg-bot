<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OnboardingStepEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $tg_id
 * @property string|null $username
 * @property string|null $location
 * @property OnboardingStepEnum $onboarding_step
 * @property int $level
 * @property int $exp
 * @property int $silver
 * @property int $gold
 * @property int $strength
 * @property int $agility
 * @property int $instinct
 * @property int $vitality
 * @property int $current_hp
 * @property CarbonInterface $last_hp_update
 * @property int $stat_points
 * @property int $potions
 * @property array<string, int>|null $gem_pouch
 * @property int $gem_insurance_charges
 * @property int $arena_points
 * @property CarbonInterface|null $premium_until
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'tg_id',
    'username',
    'location',
    'onboarding_step',
    'level',
    'exp',
    'silver',
    'gold',
    'strength',
    'agility',
    'instinct',
    'vitality',
    'current_hp',
    'last_hp_update',
    'stat_points',
    'potions',
    'gem_pouch',
    'gem_insurance_charges',
    'arena_points',
    'premium_until',
])]
final class Character extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'tg_id';

    protected $keyType = 'int';

    /**
     * @return HasOne<Fight, $this>
     */
    public function fight(): HasOne
    {
        return $this->hasOne(Fight::class, 'tg_id', 'tg_id');
    }

    /**
     * @return HasMany<Inventory, $this>
     */
    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class, 'tg_id', 'tg_id');
    }

    /**
     * @return HasMany<Inventory, $this>
     */
    public function equippedInventories(): HasMany
    {
        return $this->inventories()->where('is_equipped', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tg_id' => 'integer',
            'onboarding_step' => OnboardingStepEnum::class,
            'level' => 'integer',
            'exp' => 'integer',
            'silver' => 'integer',
            'gold' => 'integer',
            'strength' => 'integer',
            'agility' => 'integer',
            'instinct' => 'integer',
            'vitality' => 'integer',
            'current_hp' => 'integer',
            'last_hp_update' => 'datetime',
            'stat_points' => 'integer',
            'potions' => 'integer',
            'gem_pouch' => 'array',
            'gem_insurance_charges' => 'integer',
            'arena_points' => 'integer',
            'premium_until' => 'datetime',
        ];
    }
}

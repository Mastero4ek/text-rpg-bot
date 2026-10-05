<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OnboardingStepEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

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
 * @property int $max_hp
 * @property CarbonInterface $last_hp_update
 * @property int $current_stamina
 * @property int $max_stamina
 * @property CarbonInterface $last_stamina_update
 * @property int $stat_points
 * @property list<mixed>|null $gem_pouch
 * @property int $gem_ward_charges
 * @property int $inventory_max_rows
 * @property int $arena_points
 * @property CarbonInterface|null $premium_until
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Inventory> $inventories
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LoadoutSlot> $loadoutSlots
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
    'max_hp',
    'last_hp_update',
    'current_stamina',
    'max_stamina',
    'last_stamina_update',
    'stat_points',
    'gem_pouch',
    'gem_ward_charges',
    'inventory_max_rows',
    'arena_points',
    'premium_until',
])]
final class Character extends Model implements HasMedia
{
    use InteractsWithMedia;
    use SoftDeletes;

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
     * @return HasMany<LoadoutSlot, $this>
     */
    public function loadoutSlots(): HasMany
    {
        return $this->hasMany(LoadoutSlot::class, 'tg_id', 'tg_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
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
            'max_hp' => 'integer',
            'last_hp_update' => 'datetime',
            'current_stamina' => 'integer',
            'max_stamina' => 'integer',
            'last_stamina_update' => 'datetime',
            'stat_points' => 'integer',
            'gem_pouch' => 'array',
            'gem_ward_charges' => 'integer',
            'inventory_max_rows' => 'integer',
            'arena_points' => 'integer',
            'premium_until' => 'datetime',
        ];
    }
}

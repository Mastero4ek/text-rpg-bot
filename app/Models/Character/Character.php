<?php

declare(strict_types=1);

namespace App\Models\Character;

use App\Enums\Fight\FightReturnEnum;
use App\Enums\ProgressStepEnum;
use App\Enums\Quest\OverseerTrainStepEnum;
use App\Enums\Quest\QuestChapterEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Enums\SkinEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\Bag\BagItem;
use App\Models\City;
use App\Models\Fight;
use App\Models\LoadoutSlot;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $tg_id
 * @property string|null $username
 * @property SkinEnum|null $skin
 * @property int|null $birth_city_id
 * @property int|null $city_id
 * @property ProgressStepEnum $progress_step
 * @property QuestChapterEnum $quest_chapter
 * @property QuestPhaseEnum $quest_phase
 * @property bool $unlock_portal
 * @property bool $unlock_ruins
 * @property bool $unlock_forest
 * @property bool $unlock_board
 * @property bool $unlock_arena
 * @property bool $city_unlocked_full
 * @property bool $overseer_trained
 * @property bool $overseer_at_tavern
 * @property OverseerTrainStepEnum|null $overseer_train_step
 * @property bool $healer_at_tavern
 * @property bool $healer_meet_open
 * @property bool $blacksmith_at_tavern
 * @property bool $buyer_at_tavern
 * @property bool $bruiser_contract_active
 * @property bool $bruiser_knuckle_pending
 * @property bool $healer_debt_open
 * @property bool $healer_rat_loot_pending
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
 * @property CarbonInterface|null $last_action_at
 * @property int $stat_points
 * @property int $bag_max_rows
 * @property int $backpack_max_rows
 * @property int $arena_points
 * @property CarbonInterface|null $premium_until
 * @property CarbonInterface|null $banned_until
 * @property int|null $tg_chat_id
 * @property int|null $tg_message_id
 * @property list<int>|null $tg_pending_delete_ids
 * @property FightReturnEnum|null $fight_return
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read City|null $birthCity
 * @property-read City|null $city
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BackpackItem> $backpackItems
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BagItem> $bagItems
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LoadoutSlot> $loadoutSlots
 */
#[Fillable([
    'tg_id',
    'username',
    'skin',
    'birth_city_id',
    'city_id',
    'progress_step',
    'quest_chapter',
    'quest_phase',
    'unlock_portal',
    'unlock_ruins',
    'unlock_forest',
    'unlock_board',
    'unlock_arena',
    'city_unlocked_full',
    'overseer_trained',
    'overseer_at_tavern',
    'overseer_train_step',
    'healer_at_tavern',
    'healer_meet_open',
    'blacksmith_at_tavern',
    'buyer_at_tavern',
    'bruiser_contract_active',
    'bruiser_knuckle_pending',
    'healer_debt_open',
    'healer_rat_loot_pending',
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
    'last_action_at',
    'stat_points',
    'bag_max_rows',
    'backpack_max_rows',
    'arena_points',
    'premium_until',
    'banned_until',
    'tg_chat_id',
    'tg_message_id',
    'tg_pending_delete_ids',
    'fight_return',
])]
final class Character extends Model implements HasMedia
{
    use InteractsWithMedia;
    use SoftDeletes;

    public $incrementing = false;

    protected $primaryKey = 'tg_id';

    protected $keyType = 'int';

    /**
     * @return BelongsTo<City, $this>
     */
    public function birthCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'birth_city_id');
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    /**
     * @return HasOne<Fight, $this>
     */
    public function fight(): HasOne
    {
        return $this->hasOne(Fight::class, 'tg_id', 'tg_id');
    }

    /**
     * @return HasMany<BackpackItem, $this>
     */
    public function backpackItems(): HasMany
    {
        return $this->hasMany(BackpackItem::class, 'tg_id', 'tg_id');
    }

    /**
     * @return HasMany<BagItem, $this>
     */
    public function bagItems(): HasMany
    {
        return $this->hasMany(BagItem::class, 'tg_id', 'tg_id');
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
            'skin' => SkinEnum::class,
            'birth_city_id' => 'integer',
            'city_id' => 'integer',
            'progress_step' => ProgressStepEnum::class,
            'quest_chapter' => QuestChapterEnum::class,
            'quest_phase' => QuestPhaseEnum::class,
            'unlock_portal' => 'boolean',
            'unlock_ruins' => 'boolean',
            'unlock_forest' => 'boolean',
            'unlock_board' => 'boolean',
            'unlock_arena' => 'boolean',
            'city_unlocked_full' => 'boolean',
            'overseer_trained' => 'boolean',
            'overseer_at_tavern' => 'boolean',
            'overseer_train_step' => OverseerTrainStepEnum::class,
            'healer_at_tavern' => 'boolean',
            'healer_meet_open' => 'boolean',
            'blacksmith_at_tavern' => 'boolean',
            'buyer_at_tavern' => 'boolean',
            'bruiser_contract_active' => 'boolean',
            'bruiser_knuckle_pending' => 'boolean',
            'healer_debt_open' => 'boolean',
            'healer_rat_loot_pending' => 'boolean',
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
            'last_action_at' => 'datetime',
            'stat_points' => 'integer',
            'bag_max_rows' => 'integer',
            'backpack_max_rows' => 'integer',
            'arena_points' => 'integer',
            'premium_until' => 'datetime',
            'banned_until' => 'datetime',
            'tg_chat_id' => 'integer',
            'tg_message_id' => 'integer',
            'tg_pending_delete_ids' => 'array',
            'fight_return' => FightReturnEnum::class,
        ];
    }
}

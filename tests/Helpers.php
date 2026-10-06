<?php

declare(strict_types=1);

use App\Enums\Combat\StanceEnum;
use App\Enums\Equipment\SlotEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\Bag\BagItem;
use App\Models\Character;
use App\Models\Enemy\EnemyCatalog;
use App\Services\Backpack\BackpackService;
use App\Services\Backpack\LoadoutService;
use App\Services\Backpack\RepairService;
use App\Services\Bag\BagCatalog;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use App\Services\CombatService;
use App\Services\EnemyService;
use App\Services\Fight\FightService;
use App\Services\GameConfig;
use App\Services\OnboardingService;
use App\Services\Shop\ShopCatalog;
use App\Services\Shop\ShopService;
use App\Support\ActionResult;
use App\Support\Combat\Fighter;
use App\Support\Enemy;
use App\Support\Mf;
use App\Support\Random\FakeRandomSource;
use App\Support\Random\RandomSourceContract;

function gameConfig(): GameConfig
{
    return app(GameConfig::class);
}

function shopCatalog(): ShopCatalog
{
    return app(ShopCatalog::class);
}

function bagCatalog(): BagCatalog
{
    return app(BagCatalog::class);
}

function characters(): CharacterService
{
    return app(CharacterService::class);
}

function backpack(): BackpackService
{
    return app(BackpackService::class);
}

function bag(): BagService
{
    return app(BagService::class);
}

function loadout(): LoadoutService
{
    return app(LoadoutService::class);
}

function repair(): RepairService
{
    return app(RepairService::class);
}

function shopService(): ShopService
{
    return app(ShopService::class);
}

function fights(): FightService
{
    return app(FightService::class);
}

function onboarding(): OnboardingService
{
    return app(OnboardingService::class);
}

function combat(): CombatService
{
    return app(CombatService::class);
}

function enemies(): EnemyService
{
    return app(EnemyService::class);
}

function enemyFromCatalog(string $catalogId, Character $character): Enemy
{
    $catalog = EnemyCatalog::query()->findOrFail($catalogId);

    return enemies()->makeFromCatalog($catalog, $character);
}

function woodenSoldier(Character $character): Enemy
{
    return enemyFromCatalog(EnemyCatalog::TUTORIAL_CATALOG_ID, $character);
}

function giveStarterKnuckles(int $tgId): BackpackItem
{
    $itemId = shopCatalog()->starterKnucklesId();
    backpack()->addItem($tgId, $itemId);

    return backpack()->findOwned($tgId, $itemId);
}

function giveAndEquipStarterKnuckles(Character $character): Character
{
    $row = giveStarterKnuckles($character->tg_id);
    $equip = loadout()->equip($character, $row->id);

    if (! $equip->ok || ! $equip->character instanceof Character) {
        throw new RuntimeException('Failed to equip starter knuckles in test.');
    }

    return $equip->character;
}

function equipItemToSlot(Character $character, string $itemId, SlotEnum $slot): Character
{
    if (! backpack()->owns($character->tg_id, $itemId)) {
        backpack()->addItem($character->tg_id, $itemId);
    }

    $row = backpack()->findOwned($character->tg_id, $itemId);
    $equip = loadout()->equipToSlot($character, $row->id, $slot);

    if (! $equip->ok || ! $equip->character instanceof Character) {
        throw new RuntimeException("Failed to equip {$itemId} to {$slot->value} in test.");
    }

    return $equip->character;
}

/**
 * @param  list<float>  $values
 */
function fakeRandom(array $values): void
{
    app()->instance(RandomSourceContract::class, new FakeRandomSource($values));
}

function grantGem(Character $character, string $gemId, int $qty = 1): Character
{
    return bag()->grantGem($character, $gemId, $qty);
}

function grantGemDurability(Character $character, string $gemId, int $durability): Character
{
    $character = grantGem($character, $gemId, 1);
    $gem = null;

    foreach (bag()->looseGems($character) as $row) {
        if ($row->catalog_id === $gemId) {
            $gem = $row;
        }
    }

    if (! $gem instanceof Illuminate\Database\Eloquent\Model) {
        throw new RuntimeException("No loose gem {$gemId} for character {$character->tg_id}.");
    }

    $gem->durability = $durability;
    $gem->save();

    return $character->fresh();
}

function looseGem(Character $character, string $gemId): BagItem
{
    foreach (bag()->looseGems($character) as $gem) {
        if ($gem->catalog_id === $gemId) {
            return $gem;
        }
    }

    throw new RuntimeException("No loose gem {$gemId} for character {$character->tg_id}.");
}

function hasLooseGem(Character $character, string $gemId): bool
{
    foreach (bag()->looseGems($character) as $gem) {
        if ($gem->catalog_id === $gemId) {
            return true;
        }
    }

    return false;
}

function socketGem(Character $character, BackpackItem $item, string $gemId): ActionResult
{
    $gem = looseGem($character, $gemId);

    return bag()->socket($character, $item->id, $gem->id);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function fighter(array $overrides = []): Fighter
{
    $name = 'A';
    if (array_key_exists('name', $overrides) && is_string($overrides['name'])) {
        $name = $overrides['name'];
    }

    $strength = 5;
    if (array_key_exists('strength', $overrides) && is_int($overrides['strength'])) {
        $strength = $overrides['strength'];
    }

    $agility = 5;
    if (array_key_exists('agility', $overrides) && is_int($overrides['agility'])) {
        $agility = $overrides['agility'];
    }

    $instinct = 5;
    if (array_key_exists('instinct', $overrides) && is_int($overrides['instinct'])) {
        $instinct = $overrides['instinct'];
    }

    $vitality = 5;
    if (array_key_exists('vitality', $overrides) && is_int($overrides['vitality'])) {
        $vitality = $overrides['vitality'];
    }

    $weaponDamage = 0;
    if (array_key_exists('weaponDamage', $overrides) && is_int($overrides['weaponDamage'])) {
        $weaponDamage = $overrides['weaponDamage'];
    }

    $weaponMf = new Mf(0, 0, 0, 0);
    if (array_key_exists('weaponMf', $overrides) && $overrides['weaponMf'] instanceof Mf) {
        $weaponMf = $overrides['weaponMf'];
    }

    $stance = StanceEnum::DEFEND;
    if (array_key_exists('stance', $overrides) && $overrides['stance'] instanceof StanceEnum) {
        $stance = $overrides['stance'];
    }

    $armorByZone = [
        'HEAD' => 0,
        'CHEST' => 0,
        'BELLY' => 0,
        'LEGS' => 0,
    ];
    if (array_key_exists('armorByZone', $overrides) && is_array($overrides['armorByZone'])) {
        $armorByZone = $overrides['armorByZone'];
    }

    $maxStamina = $strength * 6;
    if (array_key_exists('maxStamina', $overrides) && is_int($overrides['maxStamina'])) {
        $maxStamina = $overrides['maxStamina'];
    }

    $stamina = $maxStamina;
    if (array_key_exists('stamina', $overrides) && is_int($overrides['stamina'])) {
        $stamina = $overrides['stamina'];
    }

    return new Fighter(
        $name,
        $strength,
        $agility,
        $instinct,
        $vitality,
        $weaponDamage,
        $weaponMf,
        $stance,
        $armorByZone,
        $stamina,
        $maxStamina,
    );
}

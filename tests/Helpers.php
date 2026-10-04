<?php

declare(strict_types=1);

use App\Enums\Equipment\SlotEnum;
use App\Enums\StanceEnum;
use App\Models\Character;
use App\Models\Inventory;
use App\Services\Character\CharacterService;
use App\Services\Combat\CombatService;
use App\Services\Fight\FightService;
use App\Services\Game\GameConfig;
use App\Services\Inventory\InventoryService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Shop\ShopCatalog;
use App\Services\Shop\ShopService;
use App\Support\Game\Fighter;
use App\Support\Game\Mf;
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

function characters(): CharacterService
{
    return app(CharacterService::class);
}

function inventory(): InventoryService
{
    return app(InventoryService::class);
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

function giveStarterKnuckles(int $tgId): Inventory
{
    $itemId = shopCatalog()->starterKnucklesId();
    inventory()->addItem($tgId, $itemId);

    return inventory()->findOwned($tgId, $itemId);
}

function giveAndEquipStarterKnuckles(Character $character): Character
{
    $row = giveStarterKnuckles($character->tg_id);
    $equip = inventory()->equip($character, $row->id);

    if (! $equip->ok || ! $equip->character instanceof Character) {
        throw new RuntimeException('Failed to equip starter knuckles in test.');
    }

    return $equip->character;
}

function equipItemToSlot(Character $character, string $itemId, SlotEnum $slot): Character
{
    if (! inventory()->owns($character->tg_id, $itemId)) {
        inventory()->addItem($character->tg_id, $itemId);
    }

    $row = inventory()->findOwned($character->tg_id, $itemId);
    $equip = inventory()->equipToSlot($character, $row->id, $slot);

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
    );
}

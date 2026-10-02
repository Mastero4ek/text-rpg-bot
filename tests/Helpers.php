<?php

declare(strict_types=1);

use App\Enums\StanceEnum;
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

    return new Fighter(
        $name,
        $strength,
        $agility,
        $instinct,
        $vitality,
        $weaponDamage,
        $weaponMf,
        $stance,
    );
}

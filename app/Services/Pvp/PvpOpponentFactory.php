<?php

declare(strict_types=1);

namespace App\Services\Pvp;

use App\Enums\Combat\StanceEnum;
use App\Models\Character\Character;
use App\Services\Backpack\LoadoutService;
use App\Services\Character\CharacterService;
use App\Support\Enemy;
use RuntimeException;

final class PvpOpponentFactory
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly LoadoutService $loadout,
    ) {}

    public function fromCharacter(Character $character): Enemy
    {
        $character = $this->characters->applyRegen($character);
        $loadout = $this->loadout->forCharacter($character);

        if ($character->username === null || $character->username === '') {
            throw new RuntimeException('PvP opponent requires username.');
        }

        $maxHp = $this->characters->maxHp($character);
        $maxStamina = $this->characters->maxStamina($character);
        $weaponDamage = intdiv($loadout->mainHandDamageMin + $loadout->mainHandDamageMax, 2);
        $offHandDamage = intdiv($loadout->offHandDamageMin + $loadout->offHandDamageMax, 2);

        return new Enemy(
            $character->username,
            $character->level,
            $character->strength,
            $character->agility,
            $character->instinct,
            $character->vitality,
            $maxHp,
            $character->current_hp,
            $weaponDamage,
            $loadout->mfForMainHandAttack(),
            StanceEnum::ATTACK,
            $maxStamina,
            $this->characters->clampStamina($character->current_stamina, $maxStamina),
            $loadout->armorByZone,
            $loadout->attackSlots,
            $loadout->blockSlots,
            $offHandDamage,
            $loadout->mfForOffHandAttack(),
            'pvp:' . $character->tg_id,
            0,
            0,
            0,
            [],
        );
    }
}

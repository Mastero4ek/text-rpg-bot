<?php

declare(strict_types=1);

namespace App\Actions\Backpack;

use App\Models\Character;
use App\Services\Backpack\LoadoutService;

final class BackpackApplyFightWearAction
{
    public function __construct(
        private readonly LoadoutService $loadout,
    ) {}

    /**
     * @return list<string>
     */
    public function handleAfterLose(Character $character, int $pierceCount): array
    {
        return $this->loadout->applyFightWearAfterLose($character, $pierceCount);
    }

    /**
     * @return list<string>
     */
    public function handleAfterWin(Character $character, int $pierceCount): array
    {
        return $this->loadout->applyFightWearAfterWin($character, $pierceCount);
    }
}

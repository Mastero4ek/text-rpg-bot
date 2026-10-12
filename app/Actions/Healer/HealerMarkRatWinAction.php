<?php

declare(strict_types=1);

namespace App\Actions\Healer;

use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character\Character;
use App\Services\CharacterService;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class HealerMarkRatWinAction
{
    public function __construct(
        private readonly CharacterService $characters,
    ) {}

    public function handle(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for HealerMarkRatWin.');
            }

            if (
                $locked->quest_phase !== QuestPhaseEnum::Bruiser
                || ! $locked->healer_debt_open
                || ! $locked->bruiser_contract_active
            ) {
                return ActionResult::fail(__('telegram.quest.healer.error.debt_wrong_phase'));
            }

            $locked = $this->characters->applyRegen($locked);
            $maxHp = $this->characters->maxHp($locked);
            $maxStamina = $this->characters->maxStamina($locked);
            $locked->healer_rat_loot_pending = true;
            $locked->current_hp = $maxHp;
            $locked->current_stamina = $maxStamina;
            $locked->last_hp_update = now();
            $locked->last_stamina_update = now();
            $locked->save();

            return ActionResult::ok($locked);
        });
    }
}

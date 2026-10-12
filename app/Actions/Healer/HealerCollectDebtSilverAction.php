<?php

declare(strict_types=1);

namespace App\Actions\Healer;

use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character\Character;
use App\Services\GameConfig;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class HealerCollectDebtSilverAction
{
    public function __construct(
        private readonly GameConfig $config,
    ) {}

    public function handle(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for HealerCollectDebtSilver.');
            }

            if (
                $locked->quest_phase !== QuestPhaseEnum::Bruiser
                || ! $locked->healer_debt_open
                || ! $locked->bruiser_contract_active
                || $locked->healer_rat_loot_pending
            ) {
                return ActionResult::fail(__('telegram.quest.healer.error.debt_wrong_phase'));
            }

            $cost = $this->debtSilver();

            if ($locked->silver < $cost) {
                return ActionResult::fail(__('telegram.quest.healer.error.debt_no_silver'));
            }

            $locked->silver -= $cost;
            $locked->healer_debt_open = false;
            $locked->healer_rat_loot_pending = false;
            $locked->save();

            return ActionResult::ok($locked);
        });
    }

    private function debtSilver(): int
    {
        $row = $this->debtConfig();

        if (! array_key_exists('silver', $row) || ! is_int($row['silver'])) {
            throw new RuntimeException('HealerQuest.debt.silver missing.');
        }

        return $row['silver'];
    }

    /**
     * @return array<string, mixed>
     */
    private function debtConfig(): array
    {
        $quest = $this->config->quest();

        if (! array_key_exists('rewards', $quest) || ! is_array($quest['rewards'])) {
            throw new RuntimeException('quest.rewards missing.');
        }

        if (! array_key_exists('HealerQuest', $quest['rewards']) || ! is_array($quest['rewards']['HealerQuest'])) {
            throw new RuntimeException('quest.rewards.HealerQuest missing.');
        }

        $healer = $quest['rewards']['HealerQuest'];

        if (! array_key_exists('debt', $healer) || ! is_array($healer['debt'])) {
            throw new RuntimeException('HealerQuest.debt missing.');
        }

        return $healer['debt'];
    }
}

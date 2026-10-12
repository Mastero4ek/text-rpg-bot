<?php

declare(strict_types=1);

namespace App\Actions\Healer;

use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character\Character;
use App\Models\Enemy\EnemyCatalog;
use App\Services\CharacterService;
use App\Services\EnemyService;
use App\Services\Fight\FightService;
use App\Services\GameConfig;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class HealerStartRatFightAction
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly EnemyService $enemies,
        private readonly FightService $fights,
        private readonly GameConfig $config,
    ) {}

    public function handle(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for HealerStartRatFight.');
            }

            if (
                $locked->quest_phase !== QuestPhaseEnum::Bruiser
                || ! $locked->healer_debt_open
                || ! $locked->bruiser_contract_active
                || $locked->healer_rat_loot_pending
            ) {
                return ActionResult::fail(__('telegram.quest.healer.error.debt_wrong_phase'));
            }

            $catalogId = $this->enemyCatalogId();
            $catalog = EnemyCatalog::query()->find($catalogId);

            if (! $catalog instanceof EnemyCatalog) {
                throw new RuntimeException('Healer debt enemy catalog missing: ' . $catalogId);
            }

            $locked = $this->characters->applyRegen($locked);
            $enemy = $this->enemies->makeFromCatalog($catalog, $locked);
            $this->fights->createHealerQuest($locked, $enemy);

            return ActionResult::ok($locked);
        });
    }

    private function enemyCatalogId(): string
    {
        $row = $this->debtConfig();

        if (! array_key_exists('enemy_catalog_id', $row) || ! is_string($row['enemy_catalog_id']) || $row['enemy_catalog_id'] === '') {
            throw new RuntimeException('HealerQuest.debt.enemy_catalog_id missing.');
        }

        return $row['enemy_catalog_id'];
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

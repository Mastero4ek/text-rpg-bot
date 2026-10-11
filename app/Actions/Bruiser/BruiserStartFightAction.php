<?php

declare(strict_types=1);

namespace App\Actions\Bruiser;

use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character;
use App\Services\CharacterService;
use App\Services\EnemyService;
use App\Services\Fight\FightService;
use App\Services\GameConfig;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BruiserStartFightAction
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
                throw new RuntimeException('Character missing for BruiserStartFight.');
            }

            if (
                $locked->quest_phase !== QuestPhaseEnum::Bruiser
                || ! $locked->bruiser_contract_active
                || $locked->healer_debt_open
            ) {
                return ActionResult::fail(__('telegram.quest.bruiser.error.wrong_phase'));
            }

            $reward = $this->reward();
            $locked = $this->characters->applyRegen($locked);
            $enemy = $this->enemies->makeQuestMirror(
                $locked,
                $reward['enemy_name'],
                $reward['enemy_catalog_id'],
                $reward['power_pct'],
            );
            $this->fights->createBruiserQuest($locked, $enemy);

            return ActionResult::ok($locked);
        });
    }

    /**
     * @return array{enemy_name: string, enemy_catalog_id: string, power_pct: int}
     */
    private function reward(): array
    {
        $quest = $this->config->quest();

        if (! array_key_exists('rewards', $quest) || ! is_array($quest['rewards'])) {
            throw new RuntimeException('quest.rewards missing.');
        }

        if (! array_key_exists('BruiserQuest', $quest['rewards']) || ! is_array($quest['rewards']['BruiserQuest'])) {
            throw new RuntimeException('quest.rewards.BruiserQuest missing.');
        }

        $row = $quest['rewards']['BruiserQuest'];

        if (! array_key_exists('enemy_name', $row) || ! is_string($row['enemy_name']) || $row['enemy_name'] === '') {
            throw new RuntimeException('BruiserQuest.enemy_name missing.');
        }

        if (! array_key_exists('enemy_catalog_id', $row) || ! is_string($row['enemy_catalog_id']) || $row['enemy_catalog_id'] === '') {
            throw new RuntimeException('BruiserQuest.enemy_catalog_id missing.');
        }

        if (! array_key_exists('power_pct', $row) || ! is_int($row['power_pct'])) {
            throw new RuntimeException('BruiserQuest.power_pct missing.');
        }

        return [
            'enemy_name' => $row['enemy_name'],
            'enemy_catalog_id' => $row['enemy_catalog_id'],
            'power_pct' => $row['power_pct'],
        ];
    }
}

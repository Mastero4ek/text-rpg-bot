<?php

declare(strict_types=1);

namespace App\Actions\Blacksmith;

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

final class BlacksmithStartBagFightAction
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
                throw new RuntimeException('Character missing for BlacksmithStartBagFight.');
            }

            if ($locked->blacksmith_at_tavern || $locked->quest_phase !== QuestPhaseEnum::Blacksmith) {
                return ActionResult::fail(__('telegram.quest.blacksmith.error.wrong_phase'));
            }

            if (! $locked->unlock_forest) {
                return ActionResult::fail(__('telegram.quest.blacksmith.error.wrong_phase'));
            }

            $catalogId = $this->enemyCatalogId();
            $catalog = EnemyCatalog::query()->find($catalogId);

            if (! $catalog instanceof EnemyCatalog) {
                throw new RuntimeException('Blacksmith quest enemy catalog missing: ' . $catalogId);
            }

            $locked = $this->characters->applyRegen($locked);
            $enemy = $this->enemies->makeFromCatalog($catalog, $locked);
            $this->fights->createBlacksmithQuest($locked, $enemy);

            return ActionResult::ok($locked);
        });
    }

    private function enemyCatalogId(): string
    {
        $quest = $this->config->quest();

        if (! array_key_exists('rewards', $quest) || ! is_array($quest['rewards'])) {
            throw new RuntimeException('quest.rewards missing.');
        }

        if (! array_key_exists('BlacksmithQuest', $quest['rewards']) || ! is_array($quest['rewards']['BlacksmithQuest'])) {
            throw new RuntimeException('quest.rewards.BlacksmithQuest missing.');
        }

        $row = $quest['rewards']['BlacksmithQuest'];

        if (! array_key_exists('enemy_catalog_id', $row) || ! is_string($row['enemy_catalog_id']) || $row['enemy_catalog_id'] === '') {
            throw new RuntimeException('BlacksmithQuest.enemy_catalog_id missing.');
        }

        return $row['enemy_catalog_id'];
    }
}

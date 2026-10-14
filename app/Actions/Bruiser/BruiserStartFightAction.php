<?php

declare(strict_types=1);

namespace App\Actions\Bruiser;

use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character\Character;
use App\Models\Enemy\EnemyCatalog;
use App\Services\Character\CharacterService;
use App\Services\EnemyService;
use App\Services\Fight\FightService;
use App\Services\Quest\QuestNpcPlacement;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BruiserStartFightAction
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly EnemyService $enemies,
        private readonly FightService $fights,
        private readonly QuestNpcPlacement $npcPlacement,
    ) {}

    public function handle(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for BruiserStartFight.');
            }

            if (! $this->npcPlacement->cityHasBruiser($locked)) {
                return ActionResult::fail(__('telegram.quest.bruiser.error.no_bruiser'));
            }

            if (
                $locked->quest_phase !== QuestPhaseEnum::Bruiser
                || ! $locked->bruiser_contract_active
                || $locked->healer_debt_open
            ) {
                return ActionResult::fail(__('telegram.quest.bruiser.error.wrong_phase'));
            }

            $catalog = EnemyCatalog::query()->find(EnemyCatalog::BRUISER_CATALOG_ID);

            if (! $catalog instanceof EnemyCatalog) {
                throw new RuntimeException('Bruiser enemy catalog missing.');
            }

            $locked = $this->characters->applyRegen($locked);
            $enemy = $this->enemies->makeFromCatalog($catalog, $locked);
            $this->fights->createBruiserQuest($locked, $enemy);

            return ActionResult::ok($locked);
        });
    }
}

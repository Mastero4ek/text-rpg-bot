<?php

declare(strict_types=1);

namespace App\Actions\Blacksmith;

use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character\Character;
use App\Quests\Chapter1\Chapter1ScreenProjector;
use App\Services\Backpack\BackpackService;
use App\Services\Backpack\LoadoutService;
use App\Services\Character\CharacterService;
use App\Services\GameConfig;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BlacksmithCompleteAction
{
    public function __construct(
        private readonly BackpackService $backpack,
        private readonly CharacterService $characters,
        private readonly Chapter1ScreenProjector $projector,
        private readonly GameConfig $config,
        private readonly LoadoutService $loadout,
    ) {}

    public function handle(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for BlacksmithComplete.');
            }

            if ($locked->blacksmith_at_tavern || $locked->quest_phase !== QuestPhaseEnum::Blacksmith) {
                return ActionResult::fail(__('telegram.quest.blacksmith.error.already_done'));
            }

            if (! $locked->unlock_forest) {
                return ActionResult::fail(__('telegram.quest.blacksmith.error.wrong_phase'));
            }

            $reward = $this->reward();
            $mailId = $reward['mail_catalog_id'];
            $amuletId = $reward['amulet_catalog_id'];
            $needed = 0;

            if (! $this->backpack->owns($locked->tg_id, $mailId)) {
                $needed++;
            }

            if (! $this->backpack->owns($locked->tg_id, $amuletId)) {
                $needed++;
            }

            $free = $this->backpack->maxRows($locked) - $this->backpack->rowCount($locked->tg_id);

            if ($needed > $free) {
                return ActionResult::fail(__('telegram.quest.blacksmith.error.backpack_full'));
            }

            if (! $this->backpack->owns($locked->tg_id, $mailId)) {
                $this->backpack->addItem($locked->tg_id, $mailId);
            }

            if (! $this->backpack->owns($locked->tg_id, $amuletId)) {
                $this->backpack->addItem($locked->tg_id, $amuletId);
            }

            $equip = $this->loadout->equipByItemId($locked, $mailId);

            if (! $equip->ok) {
                throw new RuntimeException('Blacksmith mail equip failed.');
            }

            $this->characters->addExpSilver($locked, $reward['exp'], $reward['silver']);
            $locked = $this->characters->findByTgId($locked->tg_id);

            $maxHp = $this->characters->maxHp($locked);
            $maxStamina = $this->characters->maxStamina($locked);
            $this->projector->writeAfterBlacksmithComplete($locked);
            $locked->current_hp = $maxHp;
            $locked->current_stamina = $maxStamina;
            $locked->last_hp_update = now();
            $locked->last_stamina_update = now();
            $locked->save();

            return ActionResult::ok($locked);
        });
    }

    /**
     * @return array{exp: int, silver: int, mail_catalog_id: string, amulet_catalog_id: string}
     */
    private function reward(): array
    {
        $quest = $this->config->quest();

        if (! array_key_exists('rewards', $quest) || ! is_array($quest['rewards'])) {
            throw new RuntimeException('quest.rewards missing.');
        }

        if (! array_key_exists('BlacksmithQuest', $quest['rewards']) || ! is_array($quest['rewards']['BlacksmithQuest'])) {
            throw new RuntimeException('quest.rewards.BlacksmithQuest missing.');
        }

        $row = $quest['rewards']['BlacksmithQuest'];

        if (! array_key_exists('exp', $row) || ! is_int($row['exp'])) {
            throw new RuntimeException('BlacksmithQuest.exp missing.');
        }

        if (! array_key_exists('silver', $row) || ! is_int($row['silver'])) {
            throw new RuntimeException('BlacksmithQuest.silver missing.');
        }

        if (! array_key_exists('mail_catalog_id', $row) || ! is_string($row['mail_catalog_id']) || $row['mail_catalog_id'] === '') {
            throw new RuntimeException('BlacksmithQuest.mail_catalog_id missing.');
        }

        if (! array_key_exists('amulet_catalog_id', $row) || ! is_string($row['amulet_catalog_id']) || $row['amulet_catalog_id'] === '') {
            throw new RuntimeException('BlacksmithQuest.amulet_catalog_id missing.');
        }

        return [
            'exp' => $row['exp'],
            'silver' => $row['silver'],
            'mail_catalog_id' => $row['mail_catalog_id'],
            'amulet_catalog_id' => $row['amulet_catalog_id'],
        ];
    }
}

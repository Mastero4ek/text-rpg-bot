<?php

declare(strict_types=1);

namespace App\Quests\Chapter1;

use App\Models\Backpack\BackpackItem;
use App\Models\Bag\BagItem;
use App\Models\Character;
use App\Models\LoadoutSlot;
use App\Services\Backpack\BackpackService;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use App\Services\GameConfig;
use RuntimeException;

final class Chapter1ScreenRewardGranter
{
    public function __construct(
        private readonly BackpackService $backpack,
        private readonly BagService $bag,
        private readonly CharacterService $characters,
        private readonly GameConfig $config,
    ) {}

    public function sync(Character $character, ?Chapter1ScreenEnum $from, Chapter1ScreenEnum $to): void
    {
        $fromRank = $this->rank($from);
        $toRank = $this->rank($to);

        if ($toRank > $fromRank) {
            for ($milestone = $fromRank + 1; $milestone <= $toRank; $milestone++) {
                $this->grantMilestone($character, $milestone);
            }

            return;
        }

        if ($toRank < $fromRank) {
            for ($milestone = $fromRank; $milestone > $toRank; $milestone--) {
                $this->revokeMilestone($character, $milestone);
            }
        }
    }

    public function rank(?Chapter1ScreenEnum $screen): int
    {
        if (! $screen instanceof Chapter1ScreenEnum) {
            return 0;
        }

        return match ($screen) {
            Chapter1ScreenEnum::RegistrationSplash,
            Chapter1ScreenEnum::RegistrationSetNick,
            Chapter1ScreenEnum::RegistrationSetCity,
            Chapter1ScreenEnum::OverseerGates,
            Chapter1ScreenEnum::OverseerTavern,
            Chapter1ScreenEnum::OverseerIntro,
            Chapter1ScreenEnum::OverseerAwaitFight,
            Chapter1ScreenEnum::OverseerStats => 0,
            Chapter1ScreenEnum::HealerMeet,
            Chapter1ScreenEnum::HealerPotionLock => 1,
            Chapter1ScreenEnum::BlacksmithMeet => 2,
            Chapter1ScreenEnum::BuyerMeet => 3,
            Chapter1ScreenEnum::BruiserArrive,
            Chapter1ScreenEnum::HealerDebt,
            Chapter1ScreenEnum::HealerRatLoot,
            Chapter1ScreenEnum::BruiserKnucklePending => 4,
            Chapter1ScreenEnum::BruiserReadyForPurse,
            Chapter1ScreenEnum::BuyerTurnIn => 5,
            Chapter1ScreenEnum::Chapter1Done => 6,
        };
    }

    private function grantMilestone(Character $character, int $milestone): void
    {
        $reward = $this->milestoneReward($milestone);
        $updated = $this->characters->addExpSilver($character, $reward['exp'], $reward['silver']);

        if ($reward['gold'] > 0) {
            $updated = $this->characters->grantGold($updated, $reward['gold']);
        }

        $this->copyEconomy($character, $updated);
    }

    private function revokeMilestone(Character $character, int $milestone): void
    {
        $reward = $this->milestoneReward($milestone);
        $updated = $this->characters->subtractExpSilver($character, $reward['exp'], $reward['silver']);

        if ($reward['gold'] > 0) {
            $updated = $this->characters->subtractGold($updated, $reward['gold']);
        }

        $this->copyEconomy($character, $updated);
        $this->stripMilestoneItems($character, $milestone);
    }

    private function copyEconomy(Character $character, Character $updated): void
    {
        $character->exp = $updated->exp;
        $character->silver = $updated->silver;
        $character->gold = $updated->gold;
        $character->level = $updated->level;
        $character->stat_points = $updated->stat_points;
        $character->max_hp = $updated->max_hp;
        $character->current_hp = $updated->current_hp;
        $character->max_stamina = $updated->max_stamina;
        $character->current_stamina = $updated->current_stamina;
    }

    private function stripMilestoneItems(Character $character, int $milestone): void
    {
        $quest = $this->config->quest();

        if (! array_key_exists('rewards', $quest) || ! is_array($quest['rewards'])) {
            throw new RuntimeException('quest.rewards missing.');
        }

        $rewards = $quest['rewards'];

        if ($milestone === 2) {
            $this->stripHealerPotions($character, $rewards);

            return;
        }

        if ($milestone === 3) {
            $this->stripBlacksmithGear($character, $rewards);

            return;
        }

        if ($milestone === 5) {
            $this->stripBruiserLoot($character, $rewards);
        }
    }

    /**
     * @param  array<string, mixed>  $rewards
     */
    private function stripHealerPotions(Character $character, array $rewards): void
    {
        if (! array_key_exists('HealerQuest', $rewards) || ! is_array($rewards['HealerQuest'])) {
            throw new RuntimeException('quest.rewards.HealerQuest missing.');
        }

        $healer = $rewards['HealerQuest'];

        if (! array_key_exists('potion_catalog_ids', $healer) || ! is_array($healer['potion_catalog_ids'])) {
            return;
        }

        foreach ($healer['potion_catalog_ids'] as $catalogId) {
            if (! is_string($catalogId) || $catalogId === '') {
                continue;
            }

            $this->stripBagCatalog($character->tg_id, $catalogId);
        }
    }

    /**
     * @param  array<string, mixed>  $rewards
     */
    private function stripBlacksmithGear(Character $character, array $rewards): void
    {
        if (! array_key_exists('BlacksmithQuest', $rewards) || ! is_array($rewards['BlacksmithQuest'])) {
            throw new RuntimeException('quest.rewards.BlacksmithQuest missing.');
        }

        $row = $rewards['BlacksmithQuest'];

        foreach (['mail_catalog_id', 'amulet_catalog_id'] as $key) {
            if (! array_key_exists($key, $row) || ! is_string($row[$key]) || $row[$key] === '') {
                continue;
            }

            $this->stripBackpackCatalog($character->tg_id, $row[$key]);
        }
    }

    /**
     * @param  array<string, mixed>  $rewards
     */
    private function stripBruiserLoot(Character $character, array $rewards): void
    {
        if (! array_key_exists('BruiserQuest', $rewards) || ! is_array($rewards['BruiserQuest'])) {
            throw new RuntimeException('quest.rewards.BruiserQuest missing.');
        }

        $row = $rewards['BruiserQuest'];

        if (array_key_exists('purse_catalog_id', $row) && is_string($row['purse_catalog_id']) && $row['purse_catalog_id'] !== '') {
            if ($this->bag->ownsQuestItem($character->tg_id, $row['purse_catalog_id'])) {
                $this->bag->removeQuestItem($character->tg_id, $row['purse_catalog_id']);
            }
        }

        if (array_key_exists('knuckle_catalog_id', $row) && is_string($row['knuckle_catalog_id']) && $row['knuckle_catalog_id'] !== '') {
            $this->stripBackpackCatalog($character->tg_id, $row['knuckle_catalog_id']);
        }
    }

    private function stripBackpackCatalog(int $tgId, string $catalogId): void
    {
        if (! $this->backpack->owns($tgId, $catalogId)) {
            return;
        }

        $item = BackpackItem::query()
            ->where('tg_id', $tgId)
            ->where('catalog_id', $catalogId)
            ->first();

        if (! $item instanceof BackpackItem) {
            return;
        }

        LoadoutSlot::query()
            ->where('backpack_item_id', $item->id)
            ->delete();

        $this->backpack->removeOne($item);
    }

    private function stripBagCatalog(int $tgId, string $catalogId): void
    {
        BagItem::query()
            ->where('tg_id', $tgId)
            ->where('catalog_id', $catalogId)
            ->whereNull('backpack_item_id')
            ->delete();
    }

    /**
     * @return array{exp: int, silver: int, gold: int}
     */
    private function milestoneReward(int $milestone): array
    {
        $quest = $this->config->quest();

        if (! array_key_exists('rewards', $quest) || ! is_array($quest['rewards'])) {
            throw new RuntimeException('quest.rewards missing.');
        }

        $rewards = $quest['rewards'];

        if ($milestone === 1) {
            return $this->pairReward($rewards, 'OverseerQuest');
        }

        if ($milestone === 2) {
            return $this->pairReward($rewards, 'HealerQuest');
        }

        if ($milestone === 3) {
            return $this->pairReward($rewards, 'BlacksmithQuest');
        }

        if ($milestone === 4) {
            return $this->buyerNestedReward($rewards, 'contract');
        }

        if ($milestone === 5) {
            return $this->pairReward($rewards, 'BruiserQuest');
        }

        if ($milestone === 6) {
            return $this->buyerNestedReward($rewards, 'turnIn');
        }

        throw new RuntimeException('Unknown Chapter1 reward milestone: ' . $milestone);
    }

    /**
     * @param  array<string, mixed>  $rewards
     * @return array{exp: int, silver: int, gold: int}
     */
    private function pairReward(array $rewards, string $key): array
    {
        if (! array_key_exists($key, $rewards) || ! is_array($rewards[$key])) {
            throw new RuntimeException('quest.rewards.' . $key . ' missing.');
        }

        $row = $rewards[$key];

        if (! array_key_exists('exp', $row) || ! is_int($row['exp'])) {
            throw new RuntimeException($key . '.exp missing.');
        }

        if (! array_key_exists('silver', $row) || ! is_int($row['silver'])) {
            throw new RuntimeException($key . '.silver missing.');
        }

        return [
            'exp' => $row['exp'],
            'silver' => $row['silver'],
            'gold' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $rewards
     * @return array{exp: int, silver: int, gold: int}
     */
    private function buyerNestedReward(array $rewards, string $nested): array
    {
        if (! array_key_exists('BuyerQuest', $rewards) || ! is_array($rewards['BuyerQuest'])) {
            throw new RuntimeException('quest.rewards.BuyerQuest missing.');
        }

        $buyer = $rewards['BuyerQuest'];

        if (! array_key_exists($nested, $buyer) || ! is_array($buyer[$nested])) {
            throw new RuntimeException('BuyerQuest.' . $nested . ' missing.');
        }

        $row = $buyer[$nested];

        if (! array_key_exists('exp', $row) || ! is_int($row['exp'])) {
            throw new RuntimeException('BuyerQuest.' . $nested . '.exp missing.');
        }

        if (! array_key_exists('silver', $row) || ! is_int($row['silver'])) {
            throw new RuntimeException('BuyerQuest.' . $nested . '.silver missing.');
        }

        $gold = 0;

        if (array_key_exists('gold', $row)) {
            if (! is_int($row['gold'])) {
                throw new RuntimeException('BuyerQuest.' . $nested . '.gold invalid.');
            }

            $gold = $row['gold'];
        }

        return [
            'exp' => $row['exp'],
            'silver' => $row['silver'],
            'gold' => $gold,
        ];
    }
}

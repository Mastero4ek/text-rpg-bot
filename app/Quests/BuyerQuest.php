<?php

declare(strict_types=1);

namespace App\Quests;

use App\Actions\Buyer\BuyerAcceptContractAction;
use App\Actions\Buyer\BuyerTurnInPurseAction;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character;
use App\Models\City;
use App\Services\Bag\BagService;
use App\Services\GameConfig;
use App\Support\ActionResult;
use App\Support\Telegram\TelegramHtml;
use RuntimeException;

final class BuyerQuest
{
    public function __construct(
        private readonly BagService $bag,
        private readonly BuyerAcceptContractAction $acceptContractAction,
        private readonly BuyerTurnInPurseAction $turnInPurseAction,
        private readonly GameConfig $config,
    ) {}

    public function acceptContract(Character $character): ActionResult
    {
        return $this->acceptContractAction->handle($character);
    }

    public function amuletCatalogId(): string
    {
        $quest = $this->config->quest();

        if (! array_key_exists('rewards', $quest) || ! is_array($quest['rewards'])) {
            throw new RuntimeException('quest.rewards missing.');
        }

        if (! array_key_exists('BuyerQuest', $quest['rewards']) || ! is_array($quest['rewards']['BuyerQuest'])) {
            throw new RuntimeException('quest.rewards.BuyerQuest missing.');
        }

        $buyer = $quest['rewards']['BuyerQuest'];

        if (! array_key_exists('contract', $buyer) || ! is_array($buyer['contract'])) {
            throw new RuntimeException('BuyerQuest.contract missing.');
        }

        $row = $buyer['contract'];

        if (! array_key_exists('amulet_catalog_id', $row) || ! is_string($row['amulet_catalog_id']) || $row['amulet_catalog_id'] === '') {
            throw new RuntimeException('BuyerQuest.contract.amulet_catalog_id missing.');
        }

        return $row['amulet_catalog_id'];
    }

    public function awaitsContract(Character $character): bool
    {
        return $character->quest_phase === QuestPhaseEnum::Buyer
            && $character->unlock_board
            && ! $character->buyer_at_tavern;
    }

    public function awaitsTurnIn(Character $character): bool
    {
        if (
            $character->quest_phase !== QuestPhaseEnum::Bruiser
            || ! $character->buyer_at_tavern
            || $character->bruiser_contract_active
        ) {
            return false;
        }

        return $this->bag->ownsQuestItem($character->tg_id, $this->purseCatalogId());
    }

    public function isAmuletSellLocked(Character $character): bool
    {
        return $character->quest_phase === QuestPhaseEnum::Buyer
            && ! $character->buyer_at_tavern;
    }

    public function meetSpeech(Character $character): string
    {
        return $this->speech('telegram.quest.buyer.meet', $character);
    }

    public function panelImagePath(): string
    {
        $path = config('bot.buyer_image');

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new RuntimeException('Buyer panel image missing.');
        }

        return $path;
    }

    public function purseCatalogId(): string
    {
        $quest = $this->config->quest();

        if (! array_key_exists('rewards', $quest) || ! is_array($quest['rewards'])) {
            throw new RuntimeException('quest.rewards missing.');
        }

        if (! array_key_exists('BuyerQuest', $quest['rewards']) || ! is_array($quest['rewards']['BuyerQuest'])) {
            throw new RuntimeException('quest.rewards.BuyerQuest missing.');
        }

        $buyer = $quest['rewards']['BuyerQuest'];

        if (! array_key_exists('turnIn', $buyer) || ! is_array($buyer['turnIn'])) {
            throw new RuntimeException('BuyerQuest.turnIn missing.');
        }

        $row = $buyer['turnIn'];

        if (! array_key_exists('purse_catalog_id', $row) || ! is_string($row['purse_catalog_id']) || $row['purse_catalog_id'] === '') {
            throw new RuntimeException('BuyerQuest.turnIn.purse_catalog_id missing.');
        }

        return $row['purse_catalog_id'];
    }

    public function turnInPurse(Character $character): ActionResult
    {
        return $this->turnInPurseAction->handle($character);
    }

    public function turnInSpeech(Character $character): string
    {
        return $this->speech('telegram.quest.buyer.turn_in', $character);
    }

    private function cityName(Character $character): string
    {
        $character->loadMissing('city');

        if (! $character->city instanceof City) {
            throw new RuntimeException('Buyer quest city missing.');
        }

        return TelegramHtml::escape($character->city->name);
    }

    private function speech(string $key, Character $character): string
    {
        return __($key, ['city' => $this->cityName($character)]);
    }
}

<?php

declare(strict_types=1);

namespace App\Quests;

use App\Actions\Healer\HealerCollectDebtSilverAction;
use App\Actions\Healer\HealerGivePotionAction;
use App\Actions\Healer\HealerMarkRatWinAction;
use App\Actions\Healer\HealerOpenMeetAction;
use App\Actions\Healer\HealerStartRatFightAction;
use App\Actions\Healer\HealerTakeRatTailAction;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character;
use App\Models\City;
use App\Models\Fight;
use App\Services\Fight\FightService;
use App\Support\ActionResult;
use App\Support\Telegram\TelegramHtml;
use RuntimeException;

final class HealerQuest
{
    public function __construct(
        private readonly HealerCollectDebtSilverAction $collectDebtSilverAction,
        private readonly HealerGivePotionAction $givePotion,
        private readonly HealerMarkRatWinAction $markRatWinAction,
        private readonly HealerOpenMeetAction $openMeetAction,
        private readonly HealerStartRatFightAction $startRatFightAction,
        private readonly HealerTakeRatTailAction $takeRatTailAction,
        private readonly FightService $fights,
    ) {}

    public function awaitsPotion(Character $character): bool
    {
        return $character->quest_phase === QuestPhaseEnum::Healer
            && $character->unlock_portal
            && ! $character->healer_at_tavern;
    }

    public function collectDebtSilver(Character $character): ActionResult
    {
        return $this->collectDebtSilverAction->handle($character);
    }

    public function completeSpeech(Character $character): string
    {
        return $this->speech('telegram.quest.healer.complete', $character);
    }

    public function debtClosedSpeech(Character $character): string
    {
        return $this->speech('telegram.quest.healer.debt_closed', $character);
    }

    public function debtSpeech(Character $character): string
    {
        return $this->speech('telegram.quest.healer.debt', $character);
    }

    public function debtWorkSpeech(Character $character): string
    {
        return $this->speech('telegram.quest.healer.debt_work', $character);
    }

    public function give(Character $character): ActionResult
    {
        return $this->givePotion->handle($character);
    }

    public function hasOpenDebt(Character $character): bool
    {
        return $character->quest_phase === QuestPhaseEnum::Bruiser
            && $character->healer_debt_open
            && $character->bruiser_contract_active;
    }

    public function hasRatLootPending(Character $character): bool
    {
        return $this->hasOpenDebt($character) && $character->healer_rat_loot_pending;
    }

    public function isPotionLocked(Character $character): bool
    {
        return $this->awaitsPotion($character) && $character->healer_meet_open;
    }

    public function markRatWin(Character $character): ActionResult
    {
        return $this->markRatWinAction->handle($character);
    }

    public function meetSpeech(Character $character): string
    {
        return $this->speech('telegram.quest.healer.meet', $character);
    }

    public function openMeet(Character $character): ActionResult
    {
        return $this->openMeetAction->handle($character);
    }

    public function panelImagePath(): string
    {
        $path = config('bot.healer_image');

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new RuntimeException('Healer panel image missing.');
        }

        return $path;
    }

    public function ratFight(Character $character): Fight
    {
        return $this->fights->findByTgId($character->tg_id);
    }

    public function ratLootSpeech(Character $character): string
    {
        return $this->speech('telegram.quest.healer.rat_loot', $character);
    }

    public function startRatFight(Character $character): ActionResult
    {
        return $this->startRatFightAction->handle($character);
    }

    public function takeRatTail(Character $character): ActionResult
    {
        return $this->takeRatTailAction->handle($character);
    }

    public function tavernPanelImagePath(): string
    {
        $path = config('bot.healer_tavern_image');

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return $this->panelImagePath();
        }

        return $path;
    }

    private function cityName(Character $character): string
    {
        $character->loadMissing('city');

        if (! $character->city instanceof City) {
            throw new RuntimeException('Healer quest city missing.');
        }

        return TelegramHtml::escape($character->city->name);
    }

    private function speech(string $key, Character $character): string
    {
        return __($key, ['city' => $this->cityName($character)]);
    }
}

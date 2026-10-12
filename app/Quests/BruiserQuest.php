<?php

declare(strict_types=1);

namespace App\Quests;

use App\Actions\Bruiser\BruiserBluffAction;
use App\Actions\Bruiser\BruiserCompleteAction;
use App\Actions\Bruiser\BruiserOpenDebtAction;
use App\Actions\Bruiser\BruiserStartFightAction;
use App\Actions\Bruiser\BruiserTakeKnuckleAction;
use App\Models\Character\Character;
use App\Models\City;
use App\Models\Fight;
use App\Services\Fight\FightService;
use App\Services\Quest\QuestNpcPlacement;
use App\Support\ActionResult;
use App\Support\Telegram\TelegramHtml;
use RuntimeException;

final class BruiserQuest
{
    public function __construct(
        private readonly BruiserBluffAction $bluffAction,
        private readonly BruiserCompleteAction $completeAction,
        private readonly BruiserOpenDebtAction $openDebtAction,
        private readonly BruiserStartFightAction $startFightAction,
        private readonly BruiserTakeKnuckleAction $takeKnuckleAction,
        private readonly FightService $fights,
        private readonly QuestNpcPlacement $npcPlacement,
    ) {}

    public function awaitsChallenge(Character $character): bool
    {
        return $this->npcPlacement->isBruiserAtArena($character);
    }

    public function bluff(Character $character): ActionResult
    {
        return $this->bluffAction->handle($character);
    }

    public function bluffSpeech(Character $character): string
    {
        return __('telegram.quest.bruiser.say.bluff') . "\n\n" . $this->speech('telegram.quest.bruiser.bluff', $character);
    }

    public function complete(Character $character): ActionResult
    {
        return $this->completeAction->handle($character);
    }

    public function completeSpeech(Character $character): string
    {
        return $this->speech('telegram.quest.bruiser.win', $character);
    }

    public function fight(Character $character): Fight
    {
        return $this->fights->findByTgId($character->tg_id);
    }

    public function fightLoseSpeech(Character $character): string
    {
        return $this->speech('telegram.quest.bruiser.fight_lose', $character);
    }

    public function hasKnucklePending(Character $character): bool
    {
        return $character->bruiser_knuckle_pending;
    }

    public function knucklePendingSpeech(Character $character): string
    {
        return $this->completeSpeech($character) . "\n\n" . __('telegram.quest.bruiser.knuckle_pending');
    }

    public function meetSpeech(Character $character): string
    {
        return $this->speech('telegram.quest.bruiser.arrive', $character);
    }

    public function openDebt(Character $character): ActionResult
    {
        return $this->openDebtAction->handle($character);
    }

    public function panelImagePath(): string
    {
        $path = config('bot.bruiser_image');

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new RuntimeException('Bruiser panel image missing.');
        }

        return $path;
    }

    public function startFight(Character $character): ActionResult
    {
        return $this->startFightAction->handle($character);
    }

    public function takeKnuckle(Character $character): ActionResult
    {
        return $this->takeKnuckleAction->handle($character);
    }

    public function talkSpeech(Character $character): string
    {
        return $this->speech('telegram.quest.bruiser.talk', $character);
    }

    public function threatSpeech(Character $character): string
    {
        return $this->talkSpeech($character) . "\n\n" . __('telegram.quest.bruiser.say.threat');
    }

    private function cityName(Character $character): string
    {
        $character->loadMissing('city');

        if (! $character->city instanceof City) {
            throw new RuntimeException('Bruiser quest city missing.');
        }

        return TelegramHtml::escape($character->city->name);
    }

    private function speech(string $key, Character $character): string
    {
        return __($key, ['city' => $this->cityName($character)]);
    }
}

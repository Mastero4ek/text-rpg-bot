<?php

declare(strict_types=1);

namespace App\Quests;

use App\Actions\Blacksmith\BlacksmithCompleteAction;
use App\Actions\Blacksmith\BlacksmithResetAfterLoseAction;
use App\Actions\Blacksmith\BlacksmithStartBagFightAction;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character\Character;
use App\Models\City;
use App\Models\Fight;
use App\Services\Fight\FightService;
use App\Support\ActionResult;
use App\Support\Telegram\TelegramHtml;
use RuntimeException;

final class BlacksmithQuest
{
    public function __construct(
        private readonly BlacksmithCompleteAction $completeAction,
        private readonly BlacksmithResetAfterLoseAction $resetAfterLoseAction,
        private readonly BlacksmithStartBagFightAction $startFightAction,
        private readonly FightService $fights,
    ) {}

    public function awaitsHelp(Character $character): bool
    {
        return $character->quest_phase === QuestPhaseEnum::Blacksmith
            && $character->unlock_forest
            && ! $character->blacksmith_at_tavern;
    }

    public function complete(Character $character): ActionResult
    {
        return $this->completeAction->handle($character);
    }

    public function completeSpeech(Character $character): string
    {
        return $this->speech('telegram.quest.blacksmith.complete', $character);
    }

    public function fight(Character $character): Fight
    {
        return $this->fights->findByTgId($character->tg_id);
    }

    public function meetSpeech(Character $character): string
    {
        return $this->speech('telegram.quest.blacksmith.meet', $character);
    }

    public function panelImagePath(): string
    {
        $path = config('bot.blacksmith_image');

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new RuntimeException('Blacksmith panel image missing.');
        }

        return $path;
    }

    public function resetAfterLose(Character $character): ActionResult
    {
        return $this->resetAfterLoseAction->handle($character);
    }

    public function retrySpeech(Character $character): string
    {
        return $this->speech('telegram.quest.blacksmith.meet_retry', $character);
    }

    public function startFight(Character $character): ActionResult
    {
        return $this->startFightAction->handle($character);
    }

    private function cityName(Character $character): string
    {
        $character->loadMissing('city');

        if (! $character->city instanceof City) {
            throw new RuntimeException('Blacksmith quest city missing.');
        }

        return TelegramHtml::escape($character->city->name);
    }

    private function speech(string $key, Character $character): string
    {
        return __($key, ['city' => $this->cityName($character)]);
    }
}

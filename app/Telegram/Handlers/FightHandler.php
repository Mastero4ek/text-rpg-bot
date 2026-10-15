<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Enums\City\PveZoneEnum;
use App\Enums\Combat\StanceEnum;
use App\Enums\Combat\ZoneEnum;
use App\Enums\Fight\FightEndUiEnum;
use App\Enums\Fight\FightKindEnum;
use App\Models\Character\Character;
use App\Models\City;
use App\Models\Enemy\EnemyCatalog;
use App\Models\Fight;
use App\Queries\City\CityQuery;
use App\Services\EnemyService;
use App\Services\Fight\FightEndResult;
use App\Services\Fight\FightEndService;
use App\Services\Fight\FightPanelService;
use App\Services\Fight\FightRoundService;
use App\Services\Fight\FightService;
use App\Services\Fight\FightTurnService;
use App\Services\Pvp\PvpDuelOutcome;
use App\Services\Pvp\PvpRoundService;
use App\Services\Quest\QuestUnlockService;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\CityKeyboard;
use App\Telegram\Keyboards\TelegramKeyboards;
use RuntimeException;

final class FightHandler
{
    public function __construct(
        private readonly EnemyService $enemies,
        private readonly FightService $fights,
        private readonly FightRoundService $rounds,
        private readonly PvpRoundService $pvpRounds,
        private readonly FightTurnService $turns,
        private readonly FightEndService $ends,
        private readonly FightPanelService $panel,
        private readonly CityQuery $cityQuery,
        private readonly CityHandler $city,
        private readonly QuestUnlockService $questUnlocks,
        private readonly TelegramPlayerGate $gate,
        private readonly TelegramClient $telegram,
    ) {}

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();

        if ($data === 'fight:report:log') {
            $this->reportLog($responder);

            return;
        }

        if ($data === 'fight:report:summary') {
            $this->reportSummary($responder);

            return;
        }

        $responder->answerCallback();

        if (preg_match('/^fight:stance:(ATTACK|DEFEND)$/', $data, $m) === 1) {
            if (! $this->resolveExpiredTurn($update, $responder)) {
                return;
            }

            $this->stance($update, StanceEnum::from($m[1]));

            return;
        }

        if (preg_match('/^fight:atk:(HEAD|CHEST|BELLY|LEGS|POTION|STAMINA_POTION)$/', $data, $m) === 1) {
            if (! $this->resolveExpiredTurn($update, $responder)) {
                return;
            }

            $this->attack($update, $m[1]);

            return;
        }

        if (preg_match('/^fight:def:(HEAD|CHEST|BELLY|LEGS)$/', $data, $m) === 1) {
            if (! $this->resolveExpiredTurn($update, $responder)) {
                return;
            }

            $this->defend($update, $responder, ZoneEnum::from($m[1]));

            return;
        }

        if ($data === 'fight:flee') {
            $fight = $this->fights->exists($update->userId())
                ? $this->fights->findByTgId($update->userId())
                : null;

            if ($fight instanceof Fight && $fight->kind === FightKindEnum::PVP) {
                return;
            }

            if (! $this->resolveExpiredTurn($update, $responder)) {
                return;
            }

            $this->flee($update, $responder);

            return;
        }

        if (preg_match('/^fight:start:(.+)$/', $data, $m) === 1) {
            $this->startFight($update, $responder, $m[1]);
        }
    }

    private function resolveExpiredTurn(TelegramUpdate $update, TelegramResponder $responder): bool
    {
        $tgId = $update->userId();

        if (! $this->fights->exists($tgId)) {
            return false;
        }

        $fight = $this->fights->findByTgId($tgId);

        if ($fight->turn_deadline_at === null || $fight->turn_deadline_at->isFuture()) {
            return true;
        }

        $player = Character::query()->find($tgId);

        if ($player === null) {
            return false;
        }

        if ($fight->kind === FightKindEnum::PVP) {
            $this->applyPvpOutcome($this->pvpRounds->submitSkip($player), $responder);

            return false;
        }

        $outcome = $this->rounds->runSkipRound($player);

        if ($outcome->kind === 'missing' || ! $outcome->character instanceof Character || ! $outcome->fight instanceof Fight) {
            return false;
        }

        if ($outcome->kind === 'win') {
            $this->applyEnd($this->ends->finishWin($outcome->character, $outcome->fight), $outcome->fight, $responder);

            return false;
        }

        if ($outcome->kind === 'lose') {
            $this->applyEnd($this->ends->finishLose($outcome->character, $outcome->fight), $outcome->fight, $responder);

            return false;
        }

        $this->panel->refresh($this->telegram, $outcome->fight, $outcome->character);

        return false;
    }

    private function flee(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || ! $this->fights->exists($player->tg_id)) {
            return;
        }

        $outcome = $this->rounds->attemptFlee($player);

        if ($outcome->kind === 'missing' || ! $outcome->character instanceof Character || ! $outcome->fight instanceof Fight) {
            return;
        }

        if ($outcome->kind === 'flee') {
            $this->applyEnd($this->ends->finishFlee($outcome->character, $outcome->fight), $outcome->fight, $responder);

            return;
        }

        if ($outcome->kind === 'win') {
            $this->applyEnd(
                $this->withFleeFailNote($this->ends->finishWin($outcome->character, $outcome->fight)),
                $outcome->fight,
                $responder,
            );

            return;
        }

        if ($outcome->kind === 'lose') {
            $this->applyEnd(
                $this->withFleeFailNote($this->ends->finishLose($outcome->character, $outcome->fight)),
                $outcome->fight,
                $responder,
            );

            return;
        }

        $this->panel->showError(
            $this->telegram,
            $outcome->fight,
            $outcome->character,
            __('combat.flee_fail'),
        );
    }

    private function withFleeFailNote(FightEndResult $result): FightEndResult
    {
        $note = "\n\n" . __('combat.flee_fail');
        $editText = $result->editText;
        $lose = __('combat.lose');

        if (str_contains($editText, $lose)) {
            $editText = str_replace($lose, $note . $lose, $editText);
        } else {
            $editText .= $note;
        }

        return new FightEndResult(
            $result->character,
            $editText,
            $result->editUi,
            $result->replyText,
            $result->replyUi,
            $result->logText,
        );
    }

    private function reportLog(TelegramResponder $responder): void
    {
        try {
            $ok = $this->panel->showReportLog(
                $this->telegram,
                $responder->chatId(),
                $responder->messageId(),
            );
        } catch (RuntimeException) {
            $ok = false;
        }

        if (! $ok) {
            $responder->answerCallbackToast(__('combat.report_expired'));

            try {
                $this->telegram->editMessageReplyMarkup(
                    $responder->chatId(),
                    $responder->messageId(),
                    TelegramKeyboards::clearInline(),
                );
            } catch (RuntimeException) {
            }

            return;
        }

        $responder->answerCallback();
    }

    private function reportSummary(TelegramResponder $responder): void
    {
        try {
            $ok = $this->panel->showReportSummary(
                $this->telegram,
                $responder->chatId(),
                $responder->messageId(),
            );
        } catch (RuntimeException) {
            $ok = false;
        }

        if (! $ok) {
            $responder->answerCallbackToast(__('combat.report_expired'));

            try {
                $this->telegram->editMessageReplyMarkup(
                    $responder->chatId(),
                    $responder->messageId(),
                    TelegramKeyboards::clearInline(),
                );
            } catch (RuntimeException) {
            }

            return;
        }

        $responder->answerCallback();
    }

    private function stance(TelegramUpdate $update, StanceEnum $stance): void
    {
        $commit = $this->turns->commitStance($update->userId(), $stance);

        if ($commit->kind !== 'attack' || ! $commit->character instanceof Character || ! $commit->fight instanceof Fight) {
            return;
        }

        $this->panel->refresh($this->telegram, $commit->fight, $commit->character);
    }

    private function attack(TelegramUpdate $update, string $choice): void
    {
        $commit = $this->turns->commitAttack($update->userId(), $choice);

        if ($commit->kind === 'noop') {
            return;
        }

        if ($commit->kind === 'potion_denied') {
            if ($commit->character instanceof Character && $commit->fight instanceof Fight) {
                $this->panel->showError(
                    $this->telegram,
                    $commit->fight,
                    $commit->character,
                    __('errors.potion_unavailable'),
                );
            }

            return;
        }

        if (! $commit->character instanceof Character || ! $commit->fight instanceof Fight) {
            return;
        }

        $this->panel->refresh($this->telegram, $commit->fight, $commit->character);
    }

    private function defend(TelegramUpdate $update, TelegramResponder $responder, ZoneEnum $zone): void
    {
        $commit = $this->turns->commitDefend($update->userId(), $zone);

        if ($commit->kind === 'noop') {
            return;
        }

        if ($commit->kind === 'defend_second') {
            if (! $commit->character instanceof Character || ! $commit->fight instanceof Fight) {
                return;
            }

            $this->panel->refresh($this->telegram, $commit->fight, $commit->character);

            return;
        }

        if ($commit->kind !== 'run_round' || ! $commit->character instanceof Character) {
            return;
        }

        if ($this->fights->exists($commit->character->tg_id)) {
            $current = $this->fights->findByTgId($commit->character->tg_id);

            if ($current->kind === FightKindEnum::PVP) {
                $this->applyPvpOutcome($this->pvpRounds->submitReadyChoice($commit->character), $responder);

                return;
            }
        }

        $outcome = $this->rounds->runRound($commit->character);

        if ($outcome->kind === 'missing' || ! $outcome->character instanceof Character || ! $outcome->fight instanceof Fight) {
            return;
        }

        if ($outcome->kind === 'win') {
            $this->applyEnd($this->ends->finishWin($outcome->character, $outcome->fight), $outcome->fight, $responder);

            return;
        }

        if ($outcome->kind === 'lose') {
            $this->applyEnd($this->ends->finishLose($outcome->character, $outcome->fight), $outcome->fight, $responder);

            return;
        }

        $this->panel->refresh($this->telegram, $outcome->fight, $outcome->character);
    }

    private function applyPvpOutcome(PvpDuelOutcome $outcome, ?TelegramResponder $responder): void
    {
        if ($outcome->kind === 'missing') {
            return;
        }

        if ($outcome->kind === 'waiting') {
            if ($outcome->characterA instanceof Character && $outcome->fightA instanceof Fight) {
                $this->panel->refresh($this->telegram, $outcome->fightA, $outcome->characterA);
            }

            return;
        }

        if (
            ! $outcome->characterA instanceof Character
            || ! $outcome->fightA instanceof Fight
            || ! $outcome->characterB instanceof Character
            || ! $outcome->fightB instanceof Fight
        ) {
            return;
        }

        if ($outcome->kind === 'continue') {
            $this->panel->refresh($this->telegram, $outcome->fightA, $outcome->characterA);
            $this->panel->refresh($this->telegram, $outcome->fightB, $outcome->characterB);

            return;
        }

        $fightA = $outcome->fightA;
        $fightB = $outcome->fightB;
        $charA = $outcome->characterA;
        $charB = $outcome->characterB;

        if ($outcome->kind === 'aborted') {
            $this->publishPvpEnd($this->ends->finishPvpAbort($charA, $fightA), $fightA, $responder);
            $this->publishPvpEnd($this->ends->finishPvpAbort($charB, $fightB), $fightB, null);

            return;
        }

        if ($outcome->kind !== 'finished' || $outcome->winnerTgId === null) {
            return;
        }

        if ($outcome->winnerTgId === $charA->tg_id) {
            $winResult = $this->ends->finishWin($charA, $fightA);
            $loseResult = $this->ends->finishLose($charB, $fightB);
            $this->publishPvpEnd($winResult, $fightA, $responder);
            $this->publishPvpEnd($loseResult, $fightB, null);

            return;
        }

        $winResult = $this->ends->finishWin($charB, $fightB);
        $loseResult = $this->ends->finishLose($charA, $fightA);
        $this->publishPvpEnd($winResult, $fightB, $responder);
        $this->publishPvpEnd($loseResult, $fightA, null);
    }

    private function publishPvpEnd(FightEndResult $result, Fight $fight, ?TelegramResponder $responder): void
    {
        $this->panel->publishEnd(
            $this->telegram,
            $result,
            $fight->tg_chat_id,
            $fight->tg_message_id,
            $fight,
        );

        if ($result->editUi !== FightEndUiEnum::BackToCity) {
            return;
        }

        if ($responder instanceof TelegramResponder && $responder->chatId() === $fight->tg_chat_id) {
            $this->city->returnAfterFight($responder, $result->character);

            return;
        }

        if ($fight->tg_chat_id === null) {
            return;
        }

        $this->city->returnAfterFight(
            TelegramResponder::forChat($this->telegram, $fight->tg_chat_id),
            $result->character,
        );
    }

    private function applyEnd(FightEndResult $result, Fight $fight, TelegramResponder $responder): void
    {
        $this->panel->publishEnd(
            $this->telegram,
            $result,
            $fight->tg_chat_id,
            $fight->tg_message_id,
            $fight,
        );

        if (
            $result->editUi !== FightEndUiEnum::BackToCity
            && $result->editUi !== FightEndUiEnum::MainMenu
        ) {
            return;
        }

        $this->city->returnAfterFight($responder, $result->character);
    }

    private function startFight(TelegramUpdate $update, TelegramResponder $responder, string $catalogId): void
    {
        $player = $this->gate->requireCityPlayer($update, $responder);

        if ($player === false) {
            return;
        }

        if ($player->current_hp <= 0) {
            $responder->edit(__('telegram.location.error.no_hp'), CityKeyboard::backToGates());

            return;
        }

        if ($player->city_id === null) {
            $responder->edit(__('telegram.location.error.no_forest'), CityKeyboard::backToGates());

            return;
        }

        $city = City::query()->find($player->city_id);

        if (! $city instanceof City || ! $city->hasAnyPveZone()) {
            $responder->edit(__('telegram.location.error.no_forest'), CityKeyboard::backToGates());

            return;
        }

        $zone = $this->cityQuery->findZoneForCatalog($city->id, $catalogId);

        if (! $zone instanceof PveZoneEnum) {
            $responder->edit(__('telegram.location.error.enemy_not_found'), CityKeyboard::backToGates());

            return;
        }

        if (! $this->questUnlocks->canOpen($player, $zone->location())) {
            $responder->edit($this->questUnlocks->fogText($player), CityKeyboard::backToGates());

            return;
        }

        $catalog = EnemyCatalog::query()->find($catalogId);

        if (! $catalog instanceof EnemyCatalog) {
            $responder->edit(__('telegram.location.error.enemy_not_found'), CityKeyboard::backToGates());

            return;
        }

        $enemy = $this->enemies->makeFromCatalog($catalog, $player);
        $fight = $this->fights->createTrainingIn($player, $enemy, $zone->fightReturn());
        $this->panel->open($responder, $fight, $player, $catalog->localImagePath());
    }
}

<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Enums\Combat\StanceEnum;
use App\Enums\Combat\ZoneEnum;
use App\Enums\Fight\FightEndUiEnum;
use App\Models\Character;
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
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\CityKeyboard;

final class FightHandler
{
    public function __construct(
        private readonly EnemyService $enemies,
        private readonly FightService $fights,
        private readonly FightRoundService $rounds,
        private readonly FightTurnService $turns,
        private readonly FightEndService $ends,
        private readonly FightPanelService $panel,
        private readonly CityQuery $cityQuery,
        private readonly CityHandler $city,
        private readonly TelegramPlayerGate $gate,
        private readonly TelegramClient $telegram,
    ) {}

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();
        $responder->answerCallback();

        if (preg_match('/^fight:stance:(ATTACK|DEFEND)$/', $data, $m) === 1) {
            $this->stance($update, StanceEnum::from($m[1]));

            return;
        }

        if (preg_match('/^fight:atk:(HEAD|CHEST|BELLY|LEGS|POTION|STAMINA_POTION)$/', $data, $m) === 1) {
            $this->attack($update, $m[1]);

            return;
        }

        if (preg_match('/^fight:def:(HEAD|CHEST|BELLY|LEGS)$/', $data, $m) === 1) {
            $this->defend($update, $responder, ZoneEnum::from($m[1]));

            return;
        }

        if ($data === 'fight:flee') {
            $this->flee($update, $responder);

            return;
        }

        if (preg_match('/^fight:start:(.+)$/', $data, $m) === 1) {
            $this->startFight($update, $responder, $m[1]);
        }
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
        );
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

        if (! $city instanceof City || ! $city->has_forest) {
            $responder->edit(__('telegram.location.error.no_forest'), CityKeyboard::backToGates());

            return;
        }

        $catalog = null;

        foreach ($this->cityQuery->forestCatalogs($city->id) as $row) {
            if ($row->catalog_id === $catalogId) {
                $catalog = $row;
            }
        }

        if (! $catalog instanceof EnemyCatalog) {
            $responder->edit(__('telegram.location.error.enemy_not_found'), CityKeyboard::backToGates());

            return;
        }

        $enemy = $this->enemies->makeFromCatalog($catalog, $player);
        $fight = $this->fights->createTraining($player, $enemy);
        $this->panel->open($responder, $fight, $player, $catalog->localImagePath());
    }
}

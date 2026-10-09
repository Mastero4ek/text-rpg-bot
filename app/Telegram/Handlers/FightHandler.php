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
use App\Services\Fight\FightRoundService;
use App\Services\Fight\FightService;
use App\Services\Fight\FightStatusFormatter;
use App\Services\Fight\FightTurnCommit;
use App\Services\Fight\FightTurnService;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\TelegramKeyboards;

final class FightHandler
{
    public function __construct(
        private readonly EnemyService $enemies,
        private readonly FightService $fights,
        private readonly FightRoundService $rounds,
        private readonly FightTurnService $turns,
        private readonly FightEndService $ends,
        private readonly FightStatusFormatter $fightStatus,
        private readonly CityQuery $cityQuery,
        private readonly TelegramPlayerGate $gate,
    ) {}

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();
        $responder->answerCallback();

        if (
            preg_match('/^fight:(stance|atk|def):/', $data) === 1
            && $this->handleTimedOutTurn($update, $responder)
        ) {
            return;
        }

        if (preg_match('/^fight:stance:(ATTACK|DEFEND)$/', $data, $m) === 1) {
            $this->stance($update, $responder, StanceEnum::from($m[1]));

            return;
        }

        if (preg_match('/^fight:atk:(HEAD|CHEST|BELLY|LEGS|POTION|STAMINA_POTION)$/', $data, $m) === 1) {
            $this->attack($update, $responder, $m[1]);

            return;
        }

        if (preg_match('/^fight:def:(HEAD|CHEST|BELLY|LEGS)$/', $data, $m) === 1) {
            $this->defend($update, $responder, ZoneEnum::from($m[1]));

            return;
        }

        if (preg_match('/^fight:start:(.+)$/', $data, $m) === 1) {
            $this->startFight($update, $responder, $m[1]);
        }
    }

    private function handleTimedOutTurn(TelegramUpdate $update, TelegramResponder $responder): bool
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || ! $this->fights->exists($player->tg_id)) {
            return false;
        }

        $fight = $this->fights->findByTgId($player->tg_id);
        $this->fights->rememberTelegramMessage($fight, $update->chatId(), $update->messageId());
        $fight = $this->fights->findByTgId($player->tg_id);

        if (! $this->fights->turnTimedOut($fight)) {
            return false;
        }

        $outcome = $this->rounds->runSkipRound($player);

        if ($outcome->kind === 'missing' || ! $outcome->character instanceof Character || ! $outcome->fight instanceof Fight) {
            return true;
        }

        if ($outcome->kind === 'win') {
            $this->applyEnd($responder, $this->ends->finishWin($outcome->character, $outcome->fight));

            return true;
        }

        if ($outcome->kind === 'lose') {
            $this->applyEnd($responder, $this->ends->finishLose($outcome->character, $outcome->fight));

            return true;
        }

        $responder->edit(
            $this->fightStatus->format($outcome->fight, $outcome->character->username) . __('combat.pick_stance'),
            TelegramKeyboards::stance(),
        );

        return true;
    }

    private function stance(TelegramUpdate $update, TelegramResponder $responder, StanceEnum $stance): void
    {
        $commit = $this->turns->commitStance($update->userId(), $stance);

        if ($commit->kind !== 'attack' || ! $commit->character instanceof Character || ! $commit->fight instanceof Fight) {
            return;
        }

        $this->fights->rememberTelegramMessage($commit->fight, $update->chatId(), $update->messageId());
        $responder->edit(
            $this->fightStatus->format($commit->fight, $commit->character->username) . __('combat.pick_attack'),
            TelegramKeyboards::attack(
                $this->turns->availablePotionAttacks($commit->character, $commit->fight->tutorial),
            ),
        );
    }

    private function attack(TelegramUpdate $update, TelegramResponder $responder, string $choice): void
    {
        $commit = $this->turns->commitAttack($update->userId(), $choice);

        if ($commit->kind === 'noop') {
            return;
        }

        if ($commit->kind === 'potion_denied') {
            $responder->reply(__('errors.potion_unavailable'), null);

            return;
        }

        if (! $commit->character instanceof Character || ! $commit->fight instanceof Fight) {
            return;
        }

        $this->showAttackCommit($update, $responder, $commit);
    }

    private function showAttackCommit(
        TelegramUpdate $update,
        TelegramResponder $responder,
        FightTurnCommit $commit,
    ): void {
        if (! $commit->character instanceof Character || ! $commit->fight instanceof Fight) {
            return;
        }

        $this->fights->rememberTelegramMessage($commit->fight, $update->chatId(), $update->messageId());

        if ($commit->kind === 'attack_second') {
            $responder->edit(
                $this->fightStatus->format($commit->fight, $commit->character->username) . __('combat.pick_attack_second'),
                TelegramKeyboards::attackWithoutPotion(),
            );

            return;
        }

        if ($commit->fight->use_potion) {
            $prompt = __('combat.potion_then_defend');
        } else {
            $prompt = __('combat.pick_defend');
        }

        $responder->edit(
            $this->fightStatus->format($commit->fight, $commit->character->username) . $prompt,
            TelegramKeyboards::defend(),
        );
    }

    private function defend(TelegramUpdate $update, TelegramResponder $responder, ZoneEnum $zone): void
    {
        $commit = $this->turns->commitDefend($update->userId(), $zone);

        if ($commit->kind === 'noop') {
            return;
        }

        if ($commit->kind === 'defend_second') {
            if (
                ! $commit->character instanceof Character
                || ! $commit->fight instanceof Fight
                || ! $commit->firstDefend instanceof ZoneEnum
            ) {
                return;
            }

            $this->fights->rememberTelegramMessage($commit->fight, $update->chatId(), $update->messageId());
            $responder->edit(
                $this->fightStatus->format($commit->fight, $commit->character->username) . __('combat.pick_defend_second'),
                TelegramKeyboards::defendExcluding($commit->firstDefend),
            );

            return;
        }

        if ($commit->kind !== 'run_round' || ! $commit->character instanceof Character) {
            return;
        }

        $this->fights->rememberTelegramMessage(
            $this->fights->findByTgId($commit->character->tg_id),
            $update->chatId(),
            $update->messageId(),
        );
        $outcome = $this->rounds->runRound($commit->character);

        if ($outcome->kind === 'missing' || ! $outcome->character instanceof Character || ! $outcome->fight instanceof Fight) {
            return;
        }

        if ($outcome->kind === 'win') {
            $this->applyEnd($responder, $this->ends->finishWin($outcome->character, $outcome->fight));

            return;
        }

        if ($outcome->kind === 'lose') {
            $this->applyEnd($responder, $this->ends->finishLose($outcome->character, $outcome->fight));

            return;
        }

        $responder->edit(
            $this->fightStatus->format($outcome->fight, $outcome->character->username) . __('combat.pick_stance'),
            TelegramKeyboards::stance(),
        );
    }

    private function applyEnd(TelegramResponder $responder, FightEndResult $result): void
    {
        $responder->edit(
            $result->editText,
            TelegramKeyboards::fightEndMarkup($result->editUi, $result->character),
        );

        if ($result->replyText === null || $result->replyUi === FightEndUiEnum::None) {
            return;
        }

        $responder->reply(
            $result->replyText,
            TelegramKeyboards::fightEndMarkup($result->replyUi, $result->character),
        );
    }

    private function startFight(TelegramUpdate $update, TelegramResponder $responder, string $catalogId): void
    {
        $player = $this->gate->requireCityPlayer($update, $responder);

        if ($player === false) {
            return;
        }

        if ($player->current_hp <= 0) {
            $responder->reply(__('errors.no_hp'), null);

            return;
        }

        if ($player->city_id === null) {
            $responder->reply(__('errors.no_forest'), null);

            return;
        }

        $city = City::query()->find($player->city_id);

        if (! $city instanceof City || ! $city->has_forest) {
            $responder->reply(__('errors.no_forest'), null);

            return;
        }

        $catalog = null;

        foreach ($this->cityQuery->forestCatalogs($city->id) as $row) {
            if ($row->catalog_id === $catalogId) {
                $catalog = $row;
            }
        }

        if (! $catalog instanceof EnemyCatalog) {
            $responder->reply(__('errors.enemy_not_found'), null);

            return;
        }

        $enemy = $this->enemies->makeFromCatalog($catalog, $player);
        $fight = $this->fights->createTraining($player, $enemy);
        $text = $this->fightStatus->format($fight, $player->username) . __('combat.pick_stance');
        $markup = TelegramKeyboards::stance();
        $imagePath = $catalog->localImagePath();

        if ($imagePath !== null) {
            $messageId = $responder->replyPhoto($imagePath, $text, $markup);
        } else {
            $messageId = $responder->reply($text, $markup);
        }

        $this->fights->rememberTelegramMessage($fight, $update->chatId(), $messageId);
    }
}

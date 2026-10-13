<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Character\CharacterResetStatsForGoldAction;
use App\Actions\Character\CharacterSpendStatPointAction;
use App\Enums\DailyContract\DailyContractTypeEnum;
use App\Enums\Npc\NpcEnum;
use App\Enums\ProgressStepEnum;
use App\Models\Character\Character;
use App\Models\Character\CharacterDailyContract;
use App\Models\City;
use App\Quests\OverseerQuest;
use App\Services\CharacterService;
use App\Services\CityMenuService;
use App\Services\DailyContract\DailyContractService;
use App\Services\Quest\QuestNpcPlacement;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\CityKeyboard;
use App\Telegram\Keyboards\TelegramKeyboards;
use RuntimeException;
use Throwable;

final class OverseerHandler
{
    public function __construct(
        private readonly CharacterResetStatsForGoldAction $resetStatsForGold,
        private readonly CharacterService $characters,
        private readonly CharacterSpendStatPointAction $spendStatPoint,
        private readonly CityHandler $city,
        private readonly CityMenuService $cityMenu,
        private readonly DailyContractService $dailyContracts,
        private readonly OverseerQuest $overseerQuest,
        private readonly QuestNpcPlacement $npcPlacement,
        private readonly TelegramPlayerGate $gate,
    ) {}

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();
        $responder->answerCallback();
        $player = $this->gate->requireCityPlayer($update, $responder);

        if ($player === false) {
            return;
        }

        if ($data === 'city:overseer') {
            $this->overseerScreen($responder, $player);

            return;
        }

        if (preg_match('/^city:overseer:stat:(STRENGTH|AGILITY|INSTINCT|VITALITY)$/', $data, $m) === 1) {
            $this->spendStat($responder, $player, $m[1]);

            return;
        }

        if ($data === 'city:overseer:reset') {
            $this->resetConfirm($responder, $player);

            return;
        }

        if ($data === 'city:overseer:reset_yes') {
            $this->resetStats($responder, $player);
        }
    }

    /**
     * Hub entry intercept when an accepted delivery parks the overseer on the city square.
     *
     * @return bool true when the overseer panel replaced the hub
     */
    public function interceptCityEntry(TelegramResponder $responder, Character $player): bool
    {
        $panel = $this->cityEntryPanel($player);

        if ($panel === null) {
            return false;
        }

        $this->editOverseerDeliveryPanel($responder, $panel['text'], $panel['markup']);

        return true;
    }

    /**
     * New-message hub intercept (portal arrival).
     *
     * @return int|null message id when intercepted
     */
    public function sendCityEntryIntercept(TelegramResponder $responder, Character $player): ?int
    {
        $panel = $this->cityEntryPanel($player);

        if ($panel === null) {
            return null;
        }

        $image = config('bot.overseer_image');

        if (is_string($image) && $image !== '' && is_file($image)) {
            try {
                return $responder->replyPhoto($image, $panel['text'], $panel['markup']);
            } catch (Throwable) {
            }
        }

        return $responder->reply($panel['text'], $panel['markup']);
    }

    /**
     * @return array{text: string, markup: array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}}|null
     */
    private function cityEntryPanel(Character $player): ?array
    {
        if (! $this->npcPlacement->isOverseerAtHub($player)) {
            return null;
        }

        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_overseer) {
            return null;
        }

        $ready = $this->dailyContracts->turnInableForIssuer($player, $city, NpcEnum::Overseer);

        if ($ready->isEmpty()) {
            $back = $this->npcPlacement->npcBackCallback($player, NpcEnum::Overseer);

            return [
                'text' => $this->statsPanelText($player),
                'markup' => CityKeyboard::overseerWithDaily(false, $player->stat_points, $back),
            ];
        }

        $contract = $ready->firstOrFail();

        return [
            'text' => __('telegram.daily.turnin_list', [
                'target' => $this->deliveryTurnInTarget($contract),
                'silver' => $contract->reward_silver,
                'exp' => $contract->reward_exp,
            ]),
            'markup' => CityKeyboard::dailyNpcTurnIn(
                NpcEnum::Overseer,
                $contract->id,
                $this->npcPlacement->npcLaterCallback($player, NpcEnum::Overseer),
            ),
        ];
    }

    private function deliveryTurnInTarget(CharacterDailyContract $contract): string
    {
        if (
            $contract->type === DailyContractTypeEnum::Delivery
            && array_key_exists('item_name', $contract->params)
            && is_string($contract->params['item_name'])
        ) {
            return $contract->params['item_name'];
        }

        if (is_string($contract->bag_catalog_id) && $contract->bag_catalog_id !== '') {
            return $contract->bag_catalog_id;
        }

        throw new RuntimeException('Overseer delivery turn-in missing item name.');
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function editOverseerDeliveryPanel(TelegramResponder $responder, string $text, array $markup): void
    {
        $overseerImage = config('bot.overseer_image');

        if (is_string($overseerImage) && $overseerImage !== '' && is_file($overseerImage)) {
            try {
                $responder->editPhoto($overseerImage, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function editOverseerPanel(TelegramResponder $responder, string $text, array $markup): void
    {
        $overseerImage = config('bot.overseer_tavern_image');

        if (is_string($overseerImage) && $overseerImage !== '' && is_file($overseerImage)) {
            try {
                $responder->editPhoto($overseerImage, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
    }

    private function overseerScreen(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);
        $back = $this->npcPlacement->npcBackCallback($player, NpcEnum::Overseer);

        if (! $city instanceof City || ! $city->has_overseer) {
            $this->editOverseerPanel(
                $responder,
                __('telegram.npc.overseer.error.no_overseer'),
                CityKeyboard::backToCallback($back),
            );

            return;
        }

        if (
            $player->overseer_at_tavern
            && ! $player->overseer_trained
            && $player->progress_step === ProgressStepEnum::ARRIVED
        ) {
            $this->editOverseerPanel(
                $responder,
                $this->overseerQuest->tavernSpeech($player),
                TelegramKeyboards::overseerTrainOffer(),
            );

            return;
        }

        if ($player->progress_step !== ProgressStepEnum::DONE) {
            $this->city->showTavern($responder, $player);

            return;
        }

        $hasDaily = $this->dailyContracts
            ->turnInableForIssuer($player, $city, NpcEnum::Overseer)
            ->isNotEmpty();

        $this->editOverseerPanel(
            $responder,
            $this->statsPanelText($player),
            CityKeyboard::overseerWithDaily($hasDaily, $player->stat_points, $back),
        );
    }

    private function resetConfirm(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);
        $back = $this->npcPlacement->npcBackCallback($player, NpcEnum::Overseer);

        if (! $city instanceof City || ! $city->has_overseer) {
            $this->editOverseerPanel(
                $responder,
                __('telegram.npc.overseer.error.no_overseer'),
                CityKeyboard::backToCallback($back),
            );

            return;
        }

        if (! $player->overseer_trained || $player->progress_step !== ProgressStepEnum::DONE) {
            $this->overseerScreen($responder, $player);

            return;
        }

        $this->editOverseerPanel(
            $responder,
            __('telegram.npc.overseer.reset_confirm', [
                'gold' => $this->characters->statResetGoldCost(),
            ]),
            CityKeyboard::overseerResetConfirm(),
        );
    }

    private function resetDoneText(Character $character): string
    {
        return __('telegram.npc.overseer.reset_done', [
            'points' => $character->stat_points,
            'str' => $character->strength,
            'agi' => $character->agility,
            'inst' => $character->instinct,
            'vit' => $character->vitality,
        ]);
    }

    private function resetStats(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);
        $back = $this->npcPlacement->npcBackCallback($player, NpcEnum::Overseer);

        if (! $city instanceof City || ! $city->has_overseer) {
            $this->editOverseerPanel(
                $responder,
                __('telegram.npc.overseer.error.no_overseer'),
                CityKeyboard::backToCallback($back),
            );

            return;
        }

        if (! $player->overseer_trained || $player->progress_step !== ProgressStepEnum::DONE) {
            $this->overseerScreen($responder, $player);

            return;
        }

        $res = $this->resetStatsForGold->handle($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            if ($res->error === __('errors.not_enough_gold')) {
                $this->editOverseerPanel(
                    $responder,
                    __('telegram.npc.overseer.error.not_enough_gold'),
                    CityKeyboard::backToOverseer(),
                );

                return;
            }

            $this->editOverseerPanel(
                $responder,
                TelegramResponder::errorMessage($res->error),
                CityKeyboard::backToOverseer(),
            );

            return;
        }

        $hasDaily = $this->dailyContracts
            ->turnInableForIssuer($res->character, $city, NpcEnum::Overseer)
            ->isNotEmpty();

        $this->editOverseerPanel(
            $responder,
            $this->resetDoneText($res->character),
            CityKeyboard::overseerWithDaily($hasDaily, $res->character->stat_points, $back),
        );
    }

    private function spendStat(TelegramResponder $responder, Character $player, string $stat): void
    {
        $city = $this->cityMenu->currentCity($player);
        $back = $this->npcPlacement->npcBackCallback($player, NpcEnum::Overseer);

        if (! $city instanceof City || ! $city->has_overseer) {
            $this->editOverseerPanel(
                $responder,
                __('telegram.npc.overseer.error.no_overseer'),
                CityKeyboard::backToCallback($back),
            );

            return;
        }

        if (! $player->overseer_trained || $player->progress_step !== ProgressStepEnum::DONE) {
            $this->overseerScreen($responder, $player);

            return;
        }

        $res = $this->spendStatPoint->handle($player, $stat);
        $panelChar = $res->character ?? $player;
        $hasDaily = $this->dailyContracts
            ->turnInableForIssuer($panelChar, $city, NpcEnum::Overseer)
            ->isNotEmpty();

        if (! $res->ok || ! $res->character instanceof Character) {
            $this->editOverseerPanel(
                $responder,
                TelegramResponder::errorMessage($res->error),
                CityKeyboard::overseerWithDaily($hasDaily, $player->stat_points, $back),
            );

            return;
        }

        $this->editOverseerPanel(
            $responder,
            $this->statsPanelText($res->character),
            CityKeyboard::overseerWithDaily($hasDaily, $res->character->stat_points, $back),
        );
    }

    private function statsPanelText(Character $character): string
    {
        if ($character->stat_points > 0) {
            return __('telegram.npc.overseer.offer', [
                'points' => $character->stat_points,
                'str' => $character->strength,
                'agi' => $character->agility,
                'inst' => $character->instinct,
                'vit' => $character->vitality,
            ]);
        }

        return __('telegram.npc.overseer.error.no_points', [
            'str' => $character->strength,
            'agi' => $character->agility,
            'inst' => $character->instinct,
            'vit' => $character->vitality,
        ]);
    }
}

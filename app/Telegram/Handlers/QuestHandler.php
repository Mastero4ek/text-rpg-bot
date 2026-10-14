<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Character\CharacterSpendStatPointAction;
use App\Enums\Quest\OverseerTrainStepEnum;
use App\Models\Character\Character;
use App\Models\Enemy\EnemyCatalog;
use App\Quests\BlacksmithQuest;
use App\Quests\BruiserQuest;
use App\Quests\BuyerQuest;
use App\Quests\HealerQuest;
use App\Quests\OverseerQuest;
use App\Services\Character\CharacterService;
use App\Services\EnemyService;
use App\Services\Fight\FightPanelService;
use App\Services\Fight\FightService;
use App\Services\Registration\RegistrationService;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\TelegramKeyboards;
use Throwable;

final class QuestHandler
{
    public function __construct(
        private readonly OverseerQuest $overseerQuest,
        private readonly HealerQuest $healerQuest,
        private readonly BlacksmithQuest $blacksmithQuest,
        private readonly BruiserQuest $bruiserQuest,
        private readonly BuyerQuest $buyerQuest,
        private readonly CharacterSpendStatPointAction $spendStatPoint,
        private readonly CharacterService $characters,
        private readonly CityHandler $city,
        private readonly EnemyService $enemies,
        private readonly FightPanelService $fightPanel,
        private readonly FightService $fights,
        private readonly RegistrationService $registration,
    ) {}

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();
        $responder->answerCallback();

        $player = Character::query()->find($update->userId());

        if ($player === null) {
            $responder->reply(__('common.press_start'), null);

            return;
        }

        $player = $this->characters->applyRegen($player);

        if (str_starts_with($data, 'quest:healer:')) {
            $this->handleHealer($responder, $player, mb_substr($data, mb_strlen('quest:healer:')));

            return;
        }

        if (str_starts_with($data, 'quest:blacksmith:')) {
            $this->handleBlacksmith($responder, $player, mb_substr($data, mb_strlen('quest:blacksmith:')));

            return;
        }

        if (str_starts_with($data, 'quest:bruiser:')) {
            $this->handleBruiser($responder, $player, mb_substr($data, mb_strlen('quest:bruiser:')));

            return;
        }

        if (str_starts_with($data, 'quest:buyer:')) {
            $this->handleBuyer($responder, $player, mb_substr($data, mb_strlen('quest:buyer:')));

            return;
        }

        if (! str_starts_with($data, 'quest:overseer:')) {
            return;
        }

        $action = mb_substr($data, mb_strlen('quest:overseer:'));

        if ($action === 'accept') {
            $this->accept($responder, $player);

            return;
        }

        if ($action === 'skip') {
            $this->skip($responder, $player);

            return;
        }

        if ($action === 'train') {
            $this->train($responder, $player);

            return;
        }

        if ($action === 'ready') {
            $this->ready($responder, $player);

            return;
        }

        if ($action === 'submit') {
            $this->submit($responder, $player);

            return;
        }

        if (preg_match('/^stat:(STRENGTH|AGILITY|INSTINCT|VITALITY)$/', $action, $m) === 1) {
            $this->stat($responder, $player, $m[1]);
        }
    }

    public function resumeLocked(TelegramResponder $responder, Character $player): void
    {
        if ($player->overseer_train_step === OverseerTrainStepEnum::Fight) {
            if (! $this->fights->exists($player->tg_id)) {
                $res = $this->overseerQuest->resetAfterLose($player);

                if ($res->ok && $res->character instanceof Character) {
                    $this->showIntro($responder, $res->character);
                }

                return;
            }

            $fight = $this->fights->findByTgId($player->tg_id);
            $this->fightPanel->open(
                $responder,
                $fight,
                $player,
                $this->enemies->tutorialCatalog()->localImagePath(),
            );

            return;
        }

        if ($player->overseer_train_step === OverseerTrainStepEnum::Stats) {
            if ($player->stat_points <= 0) {
                $this->submit($responder, $player);

                return;
            }

            $this->editOverseerPanel(
                $responder,
                $player,
                $this->overseerQuest->statsSpeech($player),
                TelegramKeyboards::overseerTrainStats(),
            );

            return;
        }

        $this->showIntro($responder, $player);
    }

    public function showHealerMeet(TelegramResponder $responder, Character $player): int
    {
        $text = $this->healerQuest->meetSpeech($player);
        $markup = TelegramKeyboards::healerMeet();
        $image = $this->healerQuest->panelImagePath();

        try {
            $responder->editPhoto($image, $text, $markup);

            return $responder->messageId();
        } catch (Throwable) {
        }

        if ($player->tg_chat_id !== null && $player->tg_message_id !== null) {
            try {
                $responder->editPhotoAt(
                    $player->tg_chat_id,
                    $player->tg_message_id,
                    $image,
                    $text,
                    $markup,
                );

                return $player->tg_message_id;
            } catch (Throwable) {
            }
        }

        $messageId = $responder->replyPhoto($image, $text, $markup);
        $this->registration->rememberTelegramMessage($player, $responder->chatId(), $messageId);

        return $messageId;
    }

    public function showMeet(TelegramResponder $responder, Character $player): int
    {
        $text = $this->overseerQuest->meetSpeech($player);
        $markup = TelegramKeyboards::overseerMeet();
        $image = $this->overseerQuest->panelImagePath($player);

        try {
            $responder->editPhoto($image, $text, $markup);

            return $responder->messageId();
        } catch (Throwable) {
        }

        if ($player->tg_chat_id !== null && $player->tg_message_id !== null) {
            try {
                $responder->editPhotoAt(
                    $player->tg_chat_id,
                    $player->tg_message_id,
                    $image,
                    $text,
                    $markup,
                );

                return $player->tg_message_id;
            } catch (Throwable) {
            }
        }

        $messageId = $responder->replyPhoto($image, $text, $markup);
        $this->registration->rememberTelegramMessage($player, $responder->chatId(), $messageId);

        return $messageId;
    }

    public function showBruiserArrive(TelegramResponder $responder, Character $player): void
    {
        $this->editBruiserPanel(
            $responder,
            $this->bruiserQuest->meetSpeech($player),
            TelegramKeyboards::bruiserArrive(),
            $this->bruiserQuest->panelImagePath(),
        );
    }

    public function showHealerDebt(TelegramResponder $responder, Character $player): void
    {
        if (! $this->healerQuest->hasOpenDebt($player)) {
            return;
        }

        if ($this->healerQuest->hasRatLootPending($player)) {
            $this->editHealerDebtPanel(
                $responder,
                $this->healerQuest->ratLootSpeech($player),
                TelegramKeyboards::healerRatLoot(),
                $this->healerQuest->ratTailPanelImagePath(),
            );

            return;
        }

        $this->editHealerDebtPanel(
            $responder,
            $this->healerQuest->debtSpeech($player),
            TelegramKeyboards::healerDebt(),
            $this->healerQuest->tavernPanelImagePath(),
        );
    }

    private function accept(TelegramResponder $responder, Character $player): void
    {
        $res = $this->overseerQuest->accept($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            return;
        }

        $this->showIntro($responder, $res->character);
    }

    private function editHealerPanel(
        TelegramResponder $responder,
        string $text,
        array $markup,
    ): void {
        $image = $this->healerQuest->panelImagePath();

        try {
            $responder->editPhoto($image, $text, $markup);

            return;
        } catch (Throwable) {
        }

        $responder->edit($text, $markup);
    }

    private function editOverseerPanel(
        TelegramResponder $responder,
        Character $player,
        string $text,
        array $markup,
    ): void {
        $image = $this->overseerQuest->panelImagePath($player);

        try {
            $responder->editPhoto($image, $text, $markup);

            return;
        } catch (Throwable) {
        }

        if ($player->tg_chat_id !== null && $player->tg_message_id !== null) {
            try {
                $responder->editPhotoAt(
                    $player->tg_chat_id,
                    $player->tg_message_id,
                    $image,
                    $text,
                    $markup,
                );

                return;
            } catch (Throwable) {
            }
        }

        $messageId = $responder->replyPhoto($image, $text, $markup);
        $this->registration->rememberTelegramMessage($player, $responder->chatId(), $messageId);
    }

    private function editBlacksmithPanel(
        TelegramResponder $responder,
        string $text,
        array $markup,
    ): void {
        $image = $this->blacksmithQuest->panelImagePath();

        try {
            $responder->editPhoto($image, $text, $markup);

            return;
        } catch (Throwable) {
        }

        $responder->edit($text, $markup);
    }

    private function handleBlacksmith(TelegramResponder $responder, Character $player, string $action): void
    {
        if ($action === 'fight') {
            $this->startBlacksmithFight($responder, $player);
        }
    }

    private function handleBruiser(TelegramResponder $responder, Character $player, string $action): void
    {
        if ($action === 'talk') {
            $this->editBruiserPanel(
                $responder,
                $this->bruiserQuest->talkSpeech($player),
                TelegramKeyboards::bruiserTalk(),
                $this->bruiserQuest->panelImagePath(),
            );

            return;
        }

        if ($action === 'bluff') {
            $this->bluffBruiser($responder, $player);

            return;
        }

        if ($action === 'threat') {
            $this->editBruiserPanel(
                $responder,
                $this->bruiserQuest->threatSpeech($player),
                TelegramKeyboards::bruiserThreat(),
                $this->bruiserQuest->panelImagePath(),
            );

            return;
        }

        if ($action === 'fight') {
            $this->startBruiserFight($responder, $player);

            return;
        }

        if ($action === 'take_knuckle') {
            $this->takeBruiserKnuckle($responder, $player);
        }
    }

    private function handleBuyer(TelegramResponder $responder, Character $player, string $action): void
    {
        if ($action === 'accept') {
            $this->acceptBuyerContract($responder, $player);

            return;
        }

        if ($action === 'turnin') {
            $this->turnInBuyerPurse($responder, $player);
        }
    }

    private function handleHealer(TelegramResponder $responder, Character $player, string $action): void
    {
        if ($action === 'take') {
            $this->takePotion($responder, $player);

            return;
        }

        if ($action === 'wake') {
            $this->showHealerDebt($responder, $player);

            return;
        }

        if ($action === 'debt') {
            $this->showHealerDebt($responder, $player);

            return;
        }

        if ($action === 'debt_pay') {
            if ($this->healerQuest->hasRatLootPending($player)) {
                $this->showHealerDebt($responder, $player);

                return;
            }

            $this->payHealerDebt($responder, $player);

            return;
        }

        if ($action === 'debt_work') {
            if ($this->healerQuest->hasRatLootPending($player)) {
                $this->showHealerDebt($responder, $player);

                return;
            }

            $this->editHealerDebtPanel(
                $responder,
                $this->healerQuest->debtWorkSpeech($player),
                TelegramKeyboards::healerDebtWork(),
                $this->healerQuest->tavernPanelImagePath(),
            );

            return;
        }

        if ($action === 'take_tail') {
            $this->takeHealerRatTail($responder, $player);

            return;
        }

        if ($action === 'rat') {
            $this->startHealerRatFight($responder, $player);
        }
    }

    private function bluffBruiser(TelegramResponder $responder, Character $player): void
    {
        $res = $this->bruiserQuest->bluff($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            if ($res->error !== null) {
                $this->editBruiserPanel(
                    $responder,
                    $res->error,
                    TelegramKeyboards::bruiserTalk(),
                    $this->bruiserQuest->panelImagePath(),
                );
            }

            return;
        }

        $this->editBruiserPanel(
            $responder,
            $this->bruiserQuest->bluffSpeech($res->character),
            TelegramKeyboards::bruiserComplete(),
            $this->bruiserQuest->pursePanelImagePath(),
        );
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function editBruiserPanel(
        TelegramResponder $responder,
        string $text,
        array $markup,
        ?string $image,
    ): void {
        if ($image !== null) {
            try {
                $responder->editPhoto($image, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function editHealerDebtPanel(
        TelegramResponder $responder,
        string $text,
        array $markup,
        string $image,
    ): void {
        try {
            $responder->editPhoto($image, $text, $markup);

            return;
        } catch (Throwable) {
        }

        $responder->edit($text, $markup);
    }

    private function payHealerDebt(TelegramResponder $responder, Character $player): void
    {
        $res = $this->healerQuest->collectDebtSilver($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            if ($res->error !== null) {
                $this->editHealerDebtPanel(
                    $responder,
                    $res->error,
                    TelegramKeyboards::healerDebt(),
                    $this->healerQuest->tavernPanelImagePath(),
                );
            }

            return;
        }

        $this->editHealerDebtPanel(
            $responder,
            $this->healerQuest->debtClosedSpeech($res->character),
            TelegramKeyboards::healerDebtClosed(),
            $this->healerQuest->tavernPanelImagePath(),
        );
    }

    private function startBruiserFight(TelegramResponder $responder, Character $player): void
    {
        $res = $this->bruiserQuest->startFight($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            if ($res->error !== null) {
                $this->editBruiserPanel(
                    $responder,
                    $res->error,
                    TelegramKeyboards::bruiserArrive(),
                    $this->bruiserQuest->panelImagePath(),
                );
            }

            return;
        }

        $fight = $this->bruiserQuest->fight($res->character);
        $this->fightPanel->open(
            $responder,
            $fight,
            $res->character,
            $this->bruiserQuest->fightImagePath(),
        );
    }

    private function startHealerRatFight(TelegramResponder $responder, Character $player): void
    {
        if ($this->healerQuest->hasRatLootPending($player)) {
            $this->showHealerDebt($responder, $player);

            return;
        }

        $res = $this->healerQuest->startRatFight($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            if ($res->error !== null) {
                $this->editHealerDebtPanel(
                    $responder,
                    $res->error,
                    TelegramKeyboards::healerDebtWork(),
                    $this->healerQuest->tavernPanelImagePath(),
                );
            }

            return;
        }

        $fight = $this->healerQuest->ratFight($res->character);
        $enemy = $this->fights->enemy($fight);
        $catalog = EnemyCatalog::query()->find($enemy->catalogId);
        $image = null;

        if ($catalog instanceof EnemyCatalog) {
            $image = $catalog->localImagePath();
        }

        $this->fightPanel->open($responder, $fight, $res->character, $image);
    }

    private function takeBruiserKnuckle(TelegramResponder $responder, Character $player): void
    {
        $res = $this->bruiserQuest->takeKnuckle($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            if ($res->error !== null) {
                $this->editBruiserPanel(
                    $responder,
                    $res->error,
                    TelegramKeyboards::bruiserKnucklePending(),
                    $this->bruiserQuest->knucklePanelImagePath(),
                );
            }

            return;
        }

        $this->editBruiserPanel(
            $responder,
            __('telegram.quest.bruiser.knuckle_taken'),
            TelegramKeyboards::bruiserComplete(),
            $this->bruiserQuest->knucklePanelImagePath(),
        );
    }

    private function takeHealerRatTail(TelegramResponder $responder, Character $player): void
    {
        $res = $this->healerQuest->takeRatTail($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            if ($res->error !== null) {
                $this->editHealerDebtPanel(
                    $responder,
                    $res->error,
                    TelegramKeyboards::healerRatLoot(),
                    $this->healerQuest->ratTailPanelImagePath(),
                );
            }

            return;
        }

        $this->editHealerDebtPanel(
            $responder,
            $this->healerQuest->debtClosedSpeech($res->character),
            TelegramKeyboards::healerDebtClosed(),
            $this->healerQuest->tavernPanelImagePath(),
        );
    }

    private function acceptBuyerContract(TelegramResponder $responder, Character $player): void
    {
        $res = $this->buyerQuest->acceptContract($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            if ($res->error !== null) {
                $this->editBuyerPanel(
                    $responder,
                    $res->error,
                    TelegramKeyboards::buyerMeet(),
                );
            }

            return;
        }

        $this->city->home($responder, $res->character);
    }

    private function turnInBuyerPurse(TelegramResponder $responder, Character $player): void
    {
        $res = $this->buyerQuest->turnInPurse($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            if ($res->error !== null) {
                $this->editBuyerPanel(
                    $responder,
                    $res->error,
                    TelegramKeyboards::buyerTurnIn(),
                );
            }

            return;
        }

        $this->city->home($responder, $res->character);
    }

    private function editBuyerPanel(
        TelegramResponder $responder,
        string $text,
        array $markup,
    ): void {
        $image = $this->buyerQuest->panelImagePath();

        try {
            $responder->editPhoto($image, $text, $markup);

            return;
        } catch (Throwable) {
        }

        $responder->edit($text, $markup);
    }

    private function ready(TelegramResponder $responder, Character $player): void
    {
        $res = $this->overseerQuest->startFight($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            return;
        }

        $fight = $this->overseerQuest->tutorialFight($res->character);
        $this->fightPanel->open(
            $responder,
            $fight,
            $res->character,
            $this->enemies->tutorialCatalog()->localImagePath(),
        );
    }

    private function showIntro(TelegramResponder $responder, Character $player): void
    {
        $this->editOverseerPanel(
            $responder,
            $player,
            $this->overseerQuest->introSpeech(),
            TelegramKeyboards::overseerIntro(),
        );
    }

    private function skip(TelegramResponder $responder, Character $player): void
    {
        $res = $this->overseerQuest->skip($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            return;
        }

        $this->city->home($responder, $res->character);
    }

    private function stat(TelegramResponder $responder, Character $player, string $stat): void
    {
        if ($player->overseer_train_step !== OverseerTrainStepEnum::Stats) {
            return;
        }

        $res = $this->spendStatPoint->handle($player, $stat);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        if ($res->character->stat_points <= 0) {
            $this->submit($responder, $res->character);

            return;
        }

        $this->editOverseerPanel(
            $responder,
            $res->character,
            $this->overseerQuest->statsSpeech($res->character),
            TelegramKeyboards::overseerTrainStats(),
        );
    }

    private function submit(TelegramResponder $responder, Character $player): void
    {
        $res = $this->overseerQuest->completeAfterStats($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            if ($res->error !== null) {
                $responder->reply(TelegramResponder::errorMessage($res->error), null);
            }

            return;
        }

        $this->editOverseerPanel(
            $responder,
            $res->character,
            $this->overseerQuest->completeSpeech($res->character),
            TelegramKeyboards::overseerComplete(),
        );
    }

    private function startBlacksmithFight(TelegramResponder $responder, Character $player): void
    {
        $res = $this->blacksmithQuest->startFight($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            if ($res->error !== null) {
                $this->editBlacksmithPanel(
                    $responder,
                    $res->error,
                    TelegramKeyboards::blacksmithMeet(),
                );
            }

            return;
        }

        $fight = $this->blacksmithQuest->fight($res->character);
        $enemy = $this->fights->enemy($fight);
        $catalog = EnemyCatalog::query()->find($enemy->catalogId);
        $image = null;

        if ($catalog instanceof EnemyCatalog) {
            $image = $catalog->localImagePath();
        }

        $this->fightPanel->open($responder, $fight, $res->character, $image);
    }

    private function takePotion(TelegramResponder $responder, Character $player): void
    {
        $res = $this->healerQuest->give($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            if ($res->error !== null) {
                $this->editHealerPanel(
                    $responder,
                    $res->error,
                    TelegramKeyboards::healerMeet(),
                );
            }

            return;
        }

        $this->editHealerPanel(
            $responder,
            $this->healerQuest->completeSpeech($res->character),
            TelegramKeyboards::healerComplete(),
        );
    }

    private function train(TelegramResponder $responder, Character $player): void
    {
        $res = $this->overseerQuest->agreeFromTavern($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            return;
        }

        $this->showIntro($responder, $res->character);
    }
}

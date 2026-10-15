<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Pvp\PvpChallengeAcceptAction;
use App\Actions\Pvp\PvpChallengeCancelAction;
use App\Actions\Pvp\PvpChallengeCreateAction;
use App\Actions\Telegram\FlashListPageEdgeAction;
use App\Enums\Pvp\PvpChallengeStatusEnum;
use App\Models\Character\Character;
use App\Models\Fight;
use App\Models\Pvp\PvpChallenge;
use App\Services\Character\CharacterService;
use App\Services\Fight\FightPanelService;
use App\Services\Pvp\PvpBoardService;
use App\Services\Pvp\PvpChallengeService;
use App\Services\Registration\RegistrationService;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\CityKeyboard;
use App\Telegram\Keyboards\PaginatedListKeyboard;
use App\Telegram\Keyboards\TelegramKeyboards;
use Throwable;

final class PvpHandler
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly FightPanelService $panel,
        private readonly PvpBoardService $board,
        private readonly PvpChallengeService $challenges,
        private readonly PvpChallengeCreateAction $createChallenge,
        private readonly PvpChallengeCancelAction $cancelChallenge,
        private readonly PvpChallengeAcceptAction $acceptChallenge,
        private readonly RegistrationService $registration,
        private readonly TelegramPlayerGate $gate,
        private readonly TelegramClient $telegram,
    ) {}

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();
        $player = $this->gate->requireCityPlayer($update, $responder);

        if ($player === false) {
            return;
        }

        if ($data === 'pvp:create') {
            $this->create($responder, $player);

            return;
        }

        if ($data === 'pvp:cancel') {
            $this->cancel($responder, $player);

            return;
        }

        if (preg_match('/^pvp:card:(\d+)$/', $data, $m) === 1) {
            $this->card($responder, $player, (int) $m[1]);

            return;
        }

        if (preg_match('/^pvp:accept:yes:(\d+)$/', $data, $m) === 1) {
            $this->acceptYes($responder, $player, (int) $m[1]);

            return;
        }

        $listState = PaginatedListKeyboard::listState($data, 'pvp');

        if ($listState !== null) {
            $this->showList($responder, $player, $listState['page']);

            return;
        }

        if ($data === 'pvp:list' || str_starts_with($data, 'pvp:list:')) {
            $this->showList($responder, $player, 1);
        }
    }

    public function sendList(TelegramResponder $responder, Character $player, int $page): void
    {
        $panel = $this->board->panel($player, $page);

        if ($panel === null) {
            $this->board->stopWatch($player->tg_id);
            $newId = $responder->reply(
                __('telegram.location.error.city_unavailable'),
                CityKeyboard::backToArena(),
            );
            $this->registration->rememberTelegramMessage($player, $responder->chatId(), $newId);

            return;
        }

        if ($panel['image'] !== null) {
            $newId = $responder->replyPhoto($panel['image'], $panel['text'], $panel['markup']);
        } else {
            $newId = $responder->reply($panel['text'], $panel['markup']);
        }

        $this->registration->rememberTelegramMessage($player, $responder->chatId(), $newId);
        $this->board->startWatch($player, $responder->chatId(), $newId, $page);
    }

    public function showList(TelegramResponder $responder, Character $player, int $page): void
    {
        $panel = $this->board->panel($player, $page);

        if ($panel === null) {
            $this->board->stopWatch($player->tg_id);
            $responder->answerCallback();
            $responder->edit(
                __('telegram.location.error.city_unavailable'),
                CityKeyboard::backToArena(),
            );

            return;
        }

        $responder->answerCallback();
        app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());
        $this->replacePanel($responder, $player, $panel['text'], $panel['markup'], $panel['image']);

        if ($player->tg_message_id === null) {
            return;
        }

        $this->board->startWatch($player, $responder->chatId(), $player->tg_message_id, $page);
    }

    private function create(TelegramResponder $responder, Character $player): void
    {
        $res = $this->createChallenge->handle($player);

        if (! $res->ok) {
            $responder->answerCallbackToast($res->error ?? __('errors.pvp_busy'));

            return;
        }

        $this->showList($responder, $player, 1);
    }

    private function cancel(TelegramResponder $responder, Character $player): void
    {
        $res = $this->cancelChallenge->handle($player);

        if (! $res->ok) {
            $responder->answerCallbackToast($res->error ?? __('errors.pvp_no_challenge'));

            return;
        }

        $this->showList($responder, $player, 1);
    }

    private function card(TelegramResponder $responder, Character $player, int $challengeId): void
    {
        $this->board->stopWatch($player->tg_id);

        $challenge = PvpChallenge::query()->with('challenger')->find($challengeId);

        if (! $challenge instanceof PvpChallenge || $challenge->status !== PvpChallengeStatusEnum::Open) {
            $responder->answerCallbackToast(__('errors.pvp_challenge_gone'));
            $this->showList($responder, $player, 1);

            return;
        }

        $opponent = $challenge->challenger;

        if ($opponent === null) {
            $responder->answerCallbackToast(__('errors.pvp_challenge_gone'));
            $this->showList($responder, $player, 1);

            return;
        }

        $responder->answerCallback();
        $text = $this->characters->pvpFighterCardText($opponent);
        $skin = $this->characters->skinImagePath($opponent);

        $canAccept = $challenge->challenger_tg_id !== $player->tg_id
            && $this->challenges->withinLevelBracket($player, $opponent);

        $this->replacePanel(
            $responder,
            $player,
            $text,
            TelegramKeyboards::pvpCard($challenge->id, $canAccept),
            $skin,
        );
    }

    private function acceptYes(TelegramResponder $responder, Character $player, int $challengeId): void
    {
        $result = $this->acceptChallenge->handle($player, $challengeId);

        if (! $result['ok']) {
            $responder->answerCallbackToast($result['error'] ?? __('errors.pvp_challenge_gone'));
            $this->showList($responder, $player, 1);

            return;
        }

        $responder->answerCallback();

        $fightA = $result['fightA'];
        $fightB = $result['fightB'];
        $characterA = $result['characterA'];
        $characterB = $result['characterB'];

        if (
            ! $fightA instanceof Fight
            || ! $fightB instanceof Fight
            || ! $characterA instanceof Character
            || ! $characterB instanceof Character
        ) {
            return;
        }

        $this->board->stopWatch($characterA->tg_id);
        $this->board->stopWatch($characterB->tg_id);

        $warning = __('telegram.pvp.accepted_warning');

        $this->panel->openFresh(
            $this->telegram,
            (int) $responder->chatId(),
            $fightB,
            $characterB,
            $warning,
        );

        $challengerChatId = $characterA->tg_chat_id;

        if ($challengerChatId === null) {
            $challengerChatId = $characterA->tg_id;
        }

        $this->panel->openFresh(
            $this->telegram,
            $challengerChatId,
            $fightA,
            $characterA,
            $warning,
        );
    }

    /**
     * @param  array<string, mixed>|null  $markup
     */
    private function replacePanel(
        TelegramResponder $responder,
        Character $player,
        string $text,
        ?array $markup,
        ?string $imagePath,
    ): void {
        $chatId = $responder->chatId();
        $messageId = $responder->messageId();

        if ($imagePath !== null) {
            try {
                $responder->editPhotoAt($chatId, $messageId, $imagePath, $text, $markup);
                $this->registration->rememberTelegramMessage($player, $chatId, $messageId);

                return;
            } catch (Throwable) {
            }
        }

        try {
            if ($responder->callbackHasPhoto()) {
                $responder->editCaptionAt($chatId, $messageId, $text, $markup);
            } else {
                $responder->editAt($chatId, $messageId, $text, $markup);
            }

            $this->registration->rememberTelegramMessage($player, $chatId, $messageId);

            return;
        } catch (Throwable) {
        }

        if ($imagePath !== null) {
            $newId = $responder->replyPhoto($imagePath, $text, $markup);
        } else {
            $newId = $responder->reply($text, $markup);
        }

        $this->registration->rememberTelegramMessage($player, $responder->chatId(), $newId);
    }
}

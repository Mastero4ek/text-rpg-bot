<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Appeal\AppealCancelAction;
use App\Actions\Appeal\AppealCreateAction;
use App\Actions\Telegram\FlashListPageEdgeAction;
use App\Models\Appeal\Appeal;
use App\Models\Character\Character;
use App\Services\Appeal\AppealService;
use App\Services\Registration\RegistrationService;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\PaginatedListKeyboard;
use App\Telegram\Keyboards\TelegramKeyboards;
use Throwable;

final class AppealHandler
{
    public function __construct(
        private readonly AppealService $appeals,
        private readonly AppealCreateAction $createAppeal,
        private readonly AppealCancelAction $cancelAppeal,
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

        $this->appeals->clearAwait($player);

        $listState = PaginatedListKeyboard::listState($data, 'appeal');

        if ($listState !== null) {
            $this->listScreen($responder, $player, $listState['page']);

            return;
        }

        if ($data === 'appeal:new') {
            $this->composeScreen($responder, $player);

            return;
        }

        if (preg_match('/^appeal:card:(\d+)$/', $data, $m) === 1) {
            $this->cardScreen($responder, $player, (int) $m[1]);

            return;
        }

        if (preg_match('/^appeal:cancel:ask:(\d+)$/', $data, $m) === 1) {
            $this->cancelAskScreen($responder, $player, (int) $m[1]);

            return;
        }

        if (preg_match('/^appeal:cancel:yes:(\d+)$/', $data, $m) === 1) {
            $this->cancelYes($responder, $player, (int) $m[1]);
        }
    }

    public function handleText(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = $this->gate->requireCityPlayer($update, $responder);

        if ($player === false) {
            return;
        }

        if (! $this->appeals->isAwaiting($player)) {
            return;
        }

        $this->appeals->clearAwait($player);
        $responder->deleteUpdateMessage();

        $res = $this->createAppeal->handle($player, $update->text());

        if (! $res->ok || ! $res->character instanceof Character) {
            $this->showAnchoredPanel(
                $responder,
                $player,
                TelegramResponder::errorMessage($res->error),
                TelegramKeyboards::appealCompose(),
            );
            $this->appeals->beginAwait($player);

            return;
        }

        [$text, $markup] = $this->listPanel($res->character, 1);
        $text = $this->withNotice($text, __('telegram.appeal.created'));
        $this->showAnchoredPanel($responder, $res->character, $text, $markup);
    }

    public function sendList(TelegramResponder $responder, Character $player): void
    {
        $this->appeals->clearAwait($player);
        [$text, $markup] = $this->listPanel($player, 1);
        $this->sendPanel($responder, $player, $text, $markup);
    }

    public function clearAwait(Character $player): void
    {
        $this->appeals->clearAwait($player);
    }

    public function isAwaiting(Character $player): bool
    {
        return $this->appeals->isAwaiting($player);
    }

    /**
     * @return array{0: string, 1: array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}}
     */
    private function listPanel(Character $player, int $page): array
    {
        $rows = $this->appeals->appealsFor($player);
        $totalCount = $rows->count();
        $items = [];

        foreach ($rows as $ticket) {
            $items[] = [
                'text' => $this->appeals->rowText($ticket),
                'callback_data' => 'appeal:card:' . $ticket->id,
            ];
        }

        $header = __('telegram.appeal.list', ['count' => $totalCount]);

        if ($totalCount === 0) {
            $text = $header . "\n\n" . __('telegram.appeal.empty');
        } else {
            $text = $header;
        }

        return [$text, TelegramKeyboards::appealList($items, $page, $totalCount)];
    }

    private function listScreen(TelegramResponder $responder, Character $player, int $page): void
    {
        $totalCount = $this->appeals->appealsFor($player)->count();
        $edge = PaginatedListKeyboard::isOutOfRange($page, $totalCount);
        [$text, $markup] = $this->listPanel($player, $page);

        if ($edge && $totalCount > 0) {
            app(FlashListPageEdgeAction::class)->handle(
                $responder,
                __('telegram.appeal.empty'),
                $text,
                $markup,
            );

            return;
        }

        $this->showPanel($responder, $player, $text, $markup);
    }

    private function composeScreen(TelegramResponder $responder, Character $player): void
    {
        if ($this->appeals->openCount($player) >= $this->appeals->maxOpenAppeals()) {
            [$text, $markup] = $this->listPanel($player, 1);
            $text = $this->withNotice($text, __('telegram.appeal.error.open_limit', [
                'max' => $this->appeals->maxOpenAppeals(),
            ]));
            $this->showPanel($responder, $player, $text, $markup);

            return;
        }

        $this->appeals->beginAwait($player);
        $this->showPanel(
            $responder,
            $player,
            __('telegram.appeal.compose', [
                'max' => $this->appeals->maxBodyLength(),
            ]),
            TelegramKeyboards::appealCompose(),
        );
    }

    private function cardScreen(TelegramResponder $responder, Character $player, int $ticketId): void
    {
        $ticket = $this->appeals->findOwned($player, $ticketId);

        if (! $ticket instanceof Appeal) {
            [$text, $markup] = $this->listPanel($player, 1);
            $this->showPanel($responder, $player, $text, $markup);

            return;
        }

        $this->showPanel(
            $responder,
            $player,
            $this->appeals->cardText($ticket),
            TelegramKeyboards::appealCard($ticket->id, $ticket->status->canCancel()),
        );
    }

    private function cancelAskScreen(TelegramResponder $responder, Character $player, int $ticketId): void
    {
        $ticket = $this->appeals->findOwned($player, $ticketId);

        if (! $ticket instanceof Appeal || ! $ticket->status->canCancel()) {
            $this->cardScreen($responder, $player, $ticketId);

            return;
        }

        $this->showPanel(
            $responder,
            $player,
            $this->appeals->cardText($ticket) . "\n\n" . __('telegram.appeal.cancel_ask'),
            TelegramKeyboards::appealCancelConfirm($ticket->id),
        );
    }

    private function cancelYes(TelegramResponder $responder, Character $player, int $ticketId): void
    {
        $res = $this->cancelAppeal->handle($player, $ticketId);

        if (! $res->ok || ! $res->character instanceof Character) {
            [$text, $markup] = $this->listPanel($player, 1);
            $text = $this->withNotice($text, TelegramResponder::errorMessage($res->error));
            $this->showPanel($responder, $player, $text, $markup);

            return;
        }

        [$text, $markup] = $this->listPanel($res->character, 1);
        $text = $this->withNotice($text, __('telegram.appeal.cancelled'));
        $this->showPanel($responder, $res->character, $text, $markup);
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function sendPanel(
        TelegramResponder $responder,
        Character $player,
        string $text,
        array $markup,
    ): void {
        $imagePath = $this->appealImagePath();

        if ($imagePath !== null) {
            $messageId = $responder->replyPhoto($imagePath, $text, $markup);
            app(RegistrationService::class)->rememberTelegramMessage(
                $player,
                $responder->chatId(),
                $messageId,
            );

            return;
        }

        $messageId = $responder->reply($text, $markup);
        app(RegistrationService::class)->rememberTelegramMessage(
            $player,
            $responder->chatId(),
            $messageId,
        );
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function showPanel(
        TelegramResponder $responder,
        Character $player,
        string $text,
        array $markup,
    ): void {
        $imagePath = $this->appealImagePath();

        if ($imagePath !== null) {
            try {
                $responder->editPhoto($imagePath, $text, $markup);
                app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());

                return;
            } catch (Throwable) {
            }
        }

        try {
            app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());
            $responder->edit($text, $markup);

            return;
        } catch (Throwable) {
        }

        $responder->deleteUpdateMessage();
        $this->sendPanel($responder, $player, $text, $markup);
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function showAnchoredPanel(
        TelegramResponder $responder,
        Character $player,
        string $text,
        array $markup,
    ): void {
        $chatId = $player->tg_chat_id;
        $messageId = $player->tg_message_id;

        if ($chatId === null || $messageId === null) {
            $this->sendPanel($responder, $player, $text, $markup);

            return;
        }

        $imagePath = $this->appealImagePath();

        if ($imagePath !== null) {
            try {
                $responder->editPhotoAt($chatId, $messageId, $imagePath, $text, $markup);
                app(FlashListPageEdgeAction::class)->touch($chatId, $messageId);

                return;
            } catch (Throwable) {
            }
        }

        try {
            $responder->editCaptionAt($chatId, $messageId, $text, $markup);
            app(FlashListPageEdgeAction::class)->touch($chatId, $messageId);

            return;
        } catch (Throwable) {
        }

        try {
            $responder->editAt($chatId, $messageId, $text, $markup);
            app(FlashListPageEdgeAction::class)->touch($chatId, $messageId);

            return;
        } catch (Throwable) {
        }

        $this->sendPanel($responder, $player, $text, $markup);
    }

    private function appealImagePath(): ?string
    {
        $path = config('bot.appeal_image');

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return null;
        }

        return $path;
    }

    private function withNotice(string $text, string $notice): string
    {
        $parts = explode("\n\n", $text, 2);

        if (count($parts) === 1) {
            return $parts[0] . "\n\n" . $notice;
        }

        return $parts[0] . "\n\n" . $notice . "\n\n" . $parts[1];
    }
}

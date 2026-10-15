<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Telegram\FlashListPageEdgeAction;
use App\Enums\DailyContract\DailyContractStatusEnum;
use App\Models\Character\Character;
use App\Models\Character\CharacterDailyContract;
use App\Services\DailyContract\DailyContractService;
use App\Services\Registration\RegistrationService;
use App\Support\Telegram\DailyContractCopy;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\PaginatedListKeyboard;
use App\Telegram\Keyboards\TelegramKeyboards;
use Throwable;

final class JournalHandler
{
    public function __construct(
        private readonly DailyContractCopy $dailyCopy,
        private readonly DailyContractService $dailyContracts,
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

        $listState = PaginatedListKeyboard::listState($data, 'journal');

        if ($listState !== null) {
            $this->listScreen($responder, $player, $listState['filter'], $listState['page']);

            return;
        }

        if (preg_match('/^journal:card:(\d+):(all|hunt|delivery)$/', $data, $m) === 1) {
            $this->cardScreen($responder, $player, (int) $m[1], $m[2]);
        }
    }

    public function sendList(TelegramResponder $responder, Character $player): void
    {
        [$text, $markup] = $this->journalPanel($player, 'all', 1);
        $this->sendJournalPanel($responder, $player, $text, $markup);
    }

    /**
     * @return array{0: string, 1: array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}}
     */
    public function journalPanel(Character $player, string $filter, int $page): array
    {
        $filter = $this->journalFilter($filter);
        $totalCount = $this->dailyContracts->takenForJournal($player, 'all')->count();
        $rows = $this->dailyContracts->takenForJournal($player, $filter);
        $items = [];

        foreach ($rows as $contract) {
            $items[] = [
                'text' => $this->dailyCopy->rowText($contract),
                'callback_data' => 'journal:card:' . $contract->id . ':' . $filter,
            ];
        }

        $header = __('telegram.journal.list');

        if ($totalCount === 0) {
            $text = $header . "\n\n" . __('telegram.journal.empty');
        } elseif (empty($items)) {
            $text = $header . "\n\n" . __('telegram.journal.empty_type');
        } else {
            $text = $header;
        }

        return [$text, TelegramKeyboards::journalList($items, $filter, $page, $totalCount)];
    }

    private function cardScreen(
        TelegramResponder $responder,
        Character $player,
        int $contractId,
        string $filter,
    ): void {
        $filter = $this->journalFilter($filter);
        $contract = $this->dailyContracts->findOwned($player, $contractId);

        if (
            ! $contract instanceof CharacterDailyContract
            || ! $this->isTakenStatus($contract->status)
        ) {
            [$text, $markup] = $this->journalPanel($player, $filter, 1);
            $this->showJournalPanel($responder, $player, $text, $markup);

            return;
        }

        $text = $this->dailyCopy->cardText($contract);
        $markup = TelegramKeyboards::journalCard($filter);
        $image = $this->dailyCopy->offerCardImagePath($contract);

        if (is_string($image) && $image !== '' && is_file($image)) {
            try {
                $responder->editPhoto($image, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());
        $responder->edit($text, $markup);
    }

    private function isTakenStatus(DailyContractStatusEnum $status): bool
    {
        return in_array($status, [
            DailyContractStatusEnum::Accepted,
            DailyContractStatusEnum::Ready,
            DailyContractStatusEnum::Completed,
        ], true);
    }

    private function journalFilter(string $filter): string
    {
        if (in_array($filter, ['all', 'hunt', 'delivery'], true)) {
            return $filter;
        }

        return 'all';
    }

    private function journalImagePath(): ?string
    {
        $path = config('bot.journal_image');

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return null;
        }

        return $path;
    }

    private function listScreen(
        TelegramResponder $responder,
        Character $player,
        string $filter,
        int $page,
    ): void {
        $filter = $this->journalFilter($filter);
        $filteredCount = $this->dailyContracts->takenForJournal($player, $filter)->count();
        $edge = PaginatedListKeyboard::isOutOfRange($page, $filteredCount);
        [$text, $markup] = $this->journalPanel($player, $filter, $page);

        if ($edge && $filteredCount > 0) {
            app(FlashListPageEdgeAction::class)->handle(
                $responder,
                __('telegram.journal.empty_type'),
                $text,
                $markup,
            );

            return;
        }

        app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());
        $this->showJournalPanel($responder, $player, $text, $markup);
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function sendJournalPanel(
        TelegramResponder $responder,
        Character $player,
        string $text,
        array $markup,
    ): void {
        $imagePath = $this->journalImagePath();

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
    private function showJournalPanel(
        TelegramResponder $responder,
        Character $player,
        string $text,
        array $markup,
    ): void {
        $imagePath = $this->journalImagePath();

        if ($imagePath !== null) {
            try {
                $responder->editPhoto($imagePath, $text, $markup);
                app(RegistrationService::class)->rememberTelegramMessage(
                    $player,
                    $responder->chatId(),
                    $responder->messageId(),
                );

                return;
            } catch (Throwable) {
            }
        }

        if ($responder->callbackHasPhoto()) {
            try {
                $responder->edit($text, $markup);
                app(RegistrationService::class)->rememberTelegramMessage(
                    $player,
                    $responder->chatId(),
                    $responder->messageId(),
                );

                return;
            } catch (Throwable) {
            }

            $responder->deleteUpdateMessage();
            $this->sendJournalPanel($responder, $player, $text, $markup);

            return;
        }

        app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());
        $responder->edit($text, $markup);
    }
}

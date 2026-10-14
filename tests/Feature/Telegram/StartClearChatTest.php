<?php

declare(strict_types=1);

use App\Enums\ProgressStepEnum;
use App\Enums\SkinEnum;
use App\Support\LangVariant;
use App\Telegram\UpdateProcessor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Bus::fake();
    $id = 100;
    Http::fake(function (Request $request) use (&$id) {
        // Real Bot API cannot turn a text /start into a photo panel.
        if (
            str_contains($request->url(), '/editMessageMedia')
            || str_contains($request->url(), '/editMessageCaption')
            || str_contains($request->url(), '/editMessageText')
        ) {
            return Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: message to edit not found',
            ], 400);
        }

        $id++;

        return Http::response(['ok' => true, 'result' => ['message_id' => $id]]);
    });
});

/**
 * @return array{sendIndex: int|null, oldDeleteIndex: int|null, startDeleteIndex: int|null}
 */
function startClearChatPanelOrder(int $oldPanelId, int $startMessageId): array
{
    $sendIndex = null;
    $oldDeleteIndex = null;
    $startDeleteIndex = null;
    $index = -1;

    foreach (Http::recorded() as [$request]) {
        if (! $request instanceof Request) {
            continue;
        }

        if (preg_match('#/(sendPhoto|sendMessage|deleteMessage)\b#', $request->url(), $m) !== 1) {
            continue;
        }

        $index++;

        if (($m[1] === 'sendPhoto' || $m[1] === 'sendMessage') && $sendIndex === null) {
            $sendIndex = $index;
        }

        if ($m[1] !== 'deleteMessage') {
            continue;
        }

        $messageId = (int) ($request['message_id'] ?? 0);

        if ($messageId === $oldPanelId) {
            $oldDeleteIndex = $index;
        }

        if ($messageId === $startMessageId) {
            $startDeleteIndex = $index;
        }
    }

    return [
        'sendIndex' => $sendIndex,
        'oldDeleteIndex' => $oldDeleteIndex,
        'startDeleteIndex' => $startDeleteIndex,
    ];
}

it('sends a fresh home panel on /start after clear-chat for done player', function (): void {
    $player = cityDone(9701, 'ClearDone');
    $player->tg_chat_id = $player->tg_id;
    $player->tg_message_id = 44;
    $player->save();

    cityFlowText($player->tg_id, 12, '/start');

    $player->refresh();

    expect($player->tg_message_id)->not->toBe(44)
        ->and($player->tg_message_id)->not->toBe(12);

    assertCitySendHas('круговой площади');
    assertCitySendMarkupHas('city:lists');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/deleteMessage')
        && (int) ($request['message_id'] ?? 0) === 44);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/deleteMessage')
        && (int) ($request['message_id'] ?? 0) === 12);
});

it('sends home before deleting /start so the chat is never left empty', function (): void {
    $player = cityDone(9702, 'OrderDone');
    $player->tg_chat_id = $player->tg_id;
    $player->tg_message_id = 55;
    $player->save();

    cityFlowText($player->tg_id, 13, '/start');

    $order = startClearChatPanelOrder(55, 13);

    expect($order['sendIndex'])->not->toBeNull()
        ->and($order['startDeleteIndex'])->not->toBeNull()
        ->and($order['oldDeleteIndex'])->not->toBeNull()
        ->and($order['sendIndex'])->toBeLessThan($order['startDeleteIndex'])
        ->and($order['sendIndex'])->toBeLessThan($order['oldDeleteIndex']);
});

it('does not edit the stale panel on /start for done player', function (): void {
    $player = cityDone(9703, 'NoEditDone');
    $player->tg_chat_id = $player->tg_id;
    $player->tg_message_id = 66;
    $player->save();

    cityFlowText($player->tg_id, 14, '/start');

    Http::assertNotSent(function (Request $request): bool {
        if (
            ! str_contains($request->url(), '/editMessageMedia')
            && ! str_contains($request->url(), '/editMessageCaption')
            && ! str_contains($request->url(), '/editMessageText')
        ) {
            return false;
        }

        return (int) ($request['message_id'] ?? 0) === 66;
    });
});

it('does not push idle panel on /start even when the idle deadline has passed', function (): void {
    $player = cityDone(9704, 'IdleStart');
    $player->tg_chat_id = $player->tg_id;
    $player->tg_message_id = 77;
    $player->last_action_at = now()->subSeconds(10_000);
    $player->save();

    app(UpdateProcessor::class)->handle([
        'update_id' => 97041,
        'message' => [
            'message_id' => 15,
            'text' => '/start',
            'from' => ['id' => $player->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => $player->tg_id, 'type' => 'private'],
            'entities' => [['offset' => 0, 'length' => 6, 'type' => 'bot_command']],
        ],
    ]);

    $idleVariants = LangVariant::all('telegram.city.idle');

    Http::assertNotSent(function (Request $request) use ($idleVariants): bool {
        if (
            ! str_contains($request->url(), '/sendPhoto')
            && ! str_contains($request->url(), '/sendMessage')
            && ! str_contains($request->url(), '/editMessageMedia')
            && ! str_contains($request->url(), '/editMessageCaption')
            && ! str_contains($request->url(), '/editMessageText')
        ) {
            return false;
        }

        $body = $request->body();

        foreach ($idleVariants as $variant) {
            if (str_contains($body, mb_trim(strip_tags($variant)))) {
                return true;
            }

            if (str_contains($body, mb_trim($variant))) {
                return true;
            }
        }

        return false;
    });

    assertCitySendHas('круговой площади');
});

it('sends hub home on /start for arrived player who skipped overseer', function (): void {
    $player = cityArrivedSkipped(9705, 'ClearSkip');
    $player->tg_chat_id = $player->tg_id;
    $player->tg_message_id = 88;
    $player->save();

    cityFlowText($player->tg_id, 16, '/start');

    $player->refresh();

    expect($player->tg_message_id)->not->toBe(88);

    assertCitySendHas('круговой площади');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/deleteMessage')
        && (int) ($request['message_id'] ?? 0) === 88);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/deleteMessage')
        && (int) ($request['message_id'] ?? 0) === 16);
});

it('sends overseer meet on /start for arrived player awaiting meet without editing stale panel', function (): void {
    $player = cityArrived(9706, 'ClearMeet');
    $player->tg_chat_id = $player->tg_id;
    $player->tg_message_id = 99;
    $player->save();

    cityFlowText($player->tg_id, 17, '/start');

    $player->refresh();

    expect($player->tg_message_id)->not->toBe(99);

    Http::assertSent(function (Request $request): bool {
        $body = $request->body();

        return str_contains($request->url(), '/sendPhoto')
            && str_contains($body, 'quest:overseer:accept')
            && str_contains($body, 'quest:overseer:skip');
    });
    Http::assertNotSent(function (Request $request): bool {
        if (
            ! str_contains($request->url(), '/editMessageMedia')
            && ! str_contains($request->url(), '/editMessageCaption')
            && ! str_contains($request->url(), '/editMessageText')
        ) {
            return false;
        }

        return (int) ($request['message_id'] ?? 0) === 99;
    });
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/deleteMessage')
        && (int) ($request['message_id'] ?? 0) === 99);
});

it('resends mid-reg screen on /start and drops the stale registration panel', function (): void {
    $player = registration()->ensurePlayer(9707);
    $player = registration()->setNick($player, 'ClearNick')->character;
    registration()->rememberSkinPreview($player, SkinEnum::Heavy);
    registration()->rememberTelegramMessage($player, 9707, 111);

    cityFlowText(9707, 18, '/start');

    $player->refresh();

    expect($player->username)->toBe('ClearNick')
        ->and($player->progress_step)->toBe(ProgressStepEnum::SET_SKIN)
        ->and($player->tg_message_id)->not->toBe(111);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendPhoto')
            && str_contains($request->body(), mb_trim(__('telegram.registration.pick_skin', ['name' => 'ClearNick'])));
    });
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/deleteMessage')
        && (int) ($request['message_id'] ?? 0) === 111);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/deleteMessage')
        && (int) ($request['message_id'] ?? 0) === 18);
});

it('still sends home when the stale panel delete fails', function (): void {
    Http::fake(function (Request $request) {
        static $id = 200;

        if (str_contains($request->url(), '/deleteMessage')) {
            return Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: message to delete not found',
            ], 400);
        }

        if (
            str_contains($request->url(), '/editMessageMedia')
            || str_contains($request->url(), '/editMessageCaption')
            || str_contains($request->url(), '/editMessageText')
        ) {
            return Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: message to edit not found',
            ], 400);
        }

        $id++;

        return Http::response(['ok' => true, 'result' => ['message_id' => $id]]);
    });

    $player = cityDone(9708, 'DeleteFail');
    $player->tg_chat_id = $player->tg_id;
    $player->tg_message_id = 122;
    $player->save();

    cityFlowText($player->tg_id, 19, '/start');

    $player->refresh();

    expect($player->tg_message_id)->not->toBeNull()
        ->and($player->tg_message_id)->not->toBe(122);

    assertCitySendHas('круговой площади');
});

it('does not send a second home panel for a single /start', function (): void {
    $player = cityDone(9709, 'OnceDone');
    $player->tg_chat_id = $player->tg_id;
    $player->tg_message_id = 133;
    $player->save();

    cityFlowText($player->tg_id, 20, '/start');

    $homeSends = 0;

    foreach (Http::recorded() as [$request]) {
        if (! $request instanceof Request) {
            continue;
        }

        if (! str_contains($request->url(), '/sendPhoto') && ! str_contains($request->url(), '/sendMessage')) {
            continue;
        }

        if (str_contains($request->body(), 'круговой площади')) {
            $homeSends++;
        }
    }

    expect($homeSends)->toBe(1);
});

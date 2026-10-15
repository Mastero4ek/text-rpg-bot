<?php

declare(strict_types=1);

use App\Actions\Pvp\PvpChallengeCreateAction;
use App\Enums\Fight\FightKindEnum;
use App\Enums\Pvp\PvpChallengeStatusEnum;
use App\Models\City;
use App\Models\Pvp\PvpChallenge;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Telegram\Handlers\CityHandler;
use App\Telegram\Handlers\PvpHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Bus::fake();
    $id = 200;
    Http::fake(function () use (&$id) {
        $id++;

        return Http::response(['ok' => true, 'result' => ['message_id' => $id]]);
    });
});

function pvpTgPlayer(int $tgId, string $nick, string $cityKey = City::KEY_ANKRAT): App\Models\Character\Character
{
    $player = cityDone($tgId, $nick, $cityKey);
    $player->tg_chat_id = $tgId;
    $player->tg_message_id = 9;
    $player->arena_points = 25;
    $player->save();

    return $player->fresh();
}

it('opens fights list from city callback and offers create', function (): void {
    $player = pvpTgPlayer(88201, 'PvpListOpen');

    app(CityHandler::class)->handleCallback(
        cityPhotoCallback($player->tg_id, 'city:fights'),
        new TelegramResponder(app(TelegramClient::class), cityPhotoCallback($player->tg_id, 'city:fights')),
    );

    expect(fights()->exists($player->tg_id))->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        $body = urldecode($request->body());

        return (
            str_contains($request->url(), '/editMessageMedia')
            || str_contains($request->url(), '/editMessageCaption')
            || str_contains($request->url(), '/editMessageText')
        )
            && str_contains($body, 'pvp:create')
            && str_contains($body, 'city:lists')
            && ! str_contains($body, '↻')
            && ! str_contains($body, 'Твоя заявка');
    });
});

it('shows open challenges without own timer for viewer', function (): void {
    $challenger = pvpTgPlayer(88211, 'PvpRefA');
    $viewer = pvpTgPlayer(88212, 'PvpRefB');
    app(PvpChallengeCreateAction::class)->handle($challenger);

    $update = cityPhotoCallback($viewer->tg_id, 'pvp:list:all:1');
    app(PvpHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    Http::assertSent(function (Request $request): bool {
        $body = urldecode($request->body());

        return (
            str_contains($request->url(), '/editMessageMedia')
            || str_contains($request->url(), '/editMessageCaption')
            || str_contains($request->url(), '/editMessageText')
        )
            && str_contains($body, 'PvpRefA')
            && str_contains($body, 'pvp:create')
            && ! str_contains($body, 'Твоя заявка');
    });
});

it('creates an open challenge from pvp create callback', function (): void {
    $player = pvpTgPlayer(88202, 'PvpCreateCb');

    $update = cityPhotoCallback($player->tg_id, 'pvp:create');
    app(PvpHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    $challenge = PvpChallenge::query()->where('challenger_tg_id', $player->tg_id)->first();

    expect($challenge)->toBeInstanceOf(PvpChallenge::class)
        ->and($challenge->status)->toBe(PvpChallengeStatusEnum::Open);

    Http::assertSent(function (Request $request): bool {
        $body = urldecode($request->body());

        return str_contains($body, 'pvp:cancel')
            && str_contains($body, 'Твоя заявка');
    });
});

it('toasts create failure when already challenging', function (): void {
    $player = pvpTgPlayer(88203, 'PvpCreateToast');
    app(PvpChallengeCreateAction::class)->handle($player);

    $update = cityPhotoCallback($player->tg_id, 'pvp:create');
    app(PvpHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/answerCallbackQuery')
            && str_contains(urldecode($request->body()), __('errors.pvp_already_challenging'));
    });
});

it('cancels own challenge from pvp cancel callback', function (): void {
    $player = pvpTgPlayer(88204, 'PvpCancelCb');
    app(PvpChallengeCreateAction::class)->handle($player);

    $update = cityPhotoCallback($player->tg_id, 'pvp:cancel');
    app(PvpHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    expect(
        PvpChallenge::query()->where('challenger_tg_id', $player->tg_id)->firstOrFail()->status
    )->toBe(PvpChallengeStatusEnum::Cancelled);
});

it('shows opponent card with accept callback', function (): void {
    $challenger = pvpTgPlayer(88205, 'PvpCardA');
    $viewer = pvpTgPlayer(88206, 'PvpCardB');
    app(PvpChallengeCreateAction::class)->handle($challenger);
    $challenge = PvpChallenge::query()->where('challenger_tg_id', $challenger->tg_id)->firstOrFail();

    $update = cityPhotoCallback($viewer->tg_id, 'pvp:card:' . $challenge->id);
    app(PvpHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    Http::assertSent(function (Request $request) use ($challenge): bool {
        $body = urldecode($request->body());

        return (
            str_contains($request->url(), '/editMessageMedia')
            || str_contains($request->url(), '/editMessageCaption')
        )
            && str_contains($body, 'pvp:accept:yes:' . $challenge->id)
            && str_contains($body, 'PvpCardA');
    });
});

it('accepts a challenge and opens pvp fights for both players', function (): void {
    $challenger = pvpTgPlayer(88207, 'PvpYesA');
    $acceptor = pvpTgPlayer(88208, 'PvpYesB');
    app(PvpChallengeCreateAction::class)->handle($challenger);
    $challenge = PvpChallenge::query()->where('challenger_tg_id', $challenger->tg_id)->firstOrFail();

    $update = cityPhotoCallback($acceptor->tg_id, 'pvp:accept:yes:' . $challenge->id);
    app(PvpHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    expect(fights()->exists($challenger->tg_id))->toBeTrue()
        ->and(fights()->exists($acceptor->tg_id))->toBeTrue()
        ->and(fights()->findByTgId($challenger->tg_id)->kind)->toBe(FightKindEnum::PVP)
        ->and(fights()->findByTgId($acceptor->tg_id)->kind)->toBe(FightKindEnum::PVP)
        ->and($challenge->fresh()->status)->toBe(PvpChallengeStatusEnum::Accepted);

    Http::assertSent(function (Request $request): bool {
        $body = urldecode($request->body());

        return str_contains($request->url(), '/sendPhoto')
            && str_contains($body, __('telegram.pvp.accepted_warning'));
    });
});

it('rejects accept callback when level bracket fails', function (): void {
    $challenger = pvpTgPlayer(88209, 'PvpBracketA');
    $challenger->level = 1;
    $challenger->save();
    $acceptor = pvpTgPlayer(88210, 'PvpBracketB');
    $acceptor->level = 5;
    $acceptor->save();
    app(PvpChallengeCreateAction::class)->handle($challenger->fresh());
    $challenge = PvpChallenge::query()->where('challenger_tg_id', $challenger->tg_id)->firstOrFail();

    $update = cityPhotoCallback($acceptor->tg_id, 'pvp:accept:yes:' . $challenge->id);
    app(PvpHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    expect(fights()->exists($challenger->tg_id))->toBeFalse()
        ->and(fights()->exists($acceptor->tg_id))->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/answerCallbackQuery')
            && str_contains(urldecode($request->body()), __('errors.pvp_level_bracket'));
    });
});

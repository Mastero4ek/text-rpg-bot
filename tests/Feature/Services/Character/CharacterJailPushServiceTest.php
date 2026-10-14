<?php

declare(strict_types=1);

use App\Services\Character\CharacterJailPushService;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

it('defaults application timezone to Europe/Moscow', function (): void {
    expect(config('app.timezone'))->toBe('Europe/Moscow');
});

it('pushes jail panel via sendPhoto with Moscow timezone stamp', function (): void {
    Config::set('app.timezone', 'Europe/Moscow');
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]]),
    ]);

    $player = cityDone(13201, 'JailPushMsk');
    $player->tg_chat_id = 13201;
    $player->tg_message_id = 10;
    $player->banned_until = now()->addDay()->startOfMinute();
    $player->save();

    app(CharacterJailPushService::class)->pushJailed($player->fresh());

    $stamp = $player->fresh()->banned_until->timezone('Europe/Moscow')->format('d.m.Y H:i') . ' (МСК)';

    Http::assertSent(function (Request $request) use ($stamp): bool {
        return str_contains($request->url(), '/sendPhoto')
            && str_contains($request->body(), $stamp);
    });

    expect($player->fresh()->tg_message_id)->toBe(77);
});

it('falls back to sendMessage when sendPhoto fails on jail push', function (): void {
    Config::set('app.timezone', 'Europe/Moscow');
    Http::fake([
        'https://api.telegram.org/*/sendPhoto' => Http::response(['ok' => false, 'description' => 'photo fail'], 400),
        'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 88]]),
    ]);

    $player = cityDone(13202, 'JailPushFallback');
    $player->tg_chat_id = 13202;
    $player->tg_message_id = 11;
    $player->banned_until = now()->addDay()->startOfMinute();
    $player->save();

    app(CharacterJailPushService::class)->pushJailed($player->fresh());

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendPhoto'));
    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), '(МСК)');
    });

    expect($player->fresh()->tg_message_id)->toBe(88);
});

it('uses Carbon T label when app timezone is not Moscow', function (): void {
    Config::set('app.timezone', 'UTC');
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $player = cityDone(13203, 'JailPushUtc');
    $player->tg_chat_id = 13203;
    $player->banned_until = now()->addDay()->startOfMinute();
    $player->save();

    app(CharacterJailPushService::class)->pushJailed($player->fresh());

    $local = $player->fresh()->banned_until->timezone('UTC');
    $expected = $local->format('d.m.Y H:i') . ' (' . $local->format('T') . ')';

    Http::assertSent(function (Request $request) use ($expected): bool {
        return str_contains($request->url(), '/sendPhoto')
            && str_contains($request->body(), $expected)
            && ! str_contains($request->body(), '(МСК)');
    });
});

it('falls back to text nudge when sendPhoto fails', function (): void {
    Config::set('app.timezone', 'Europe/Moscow');
    Http::fake([
        'https://api.telegram.org/*/sendPhoto' => Http::response(['ok' => false, 'description' => 'photo fail'], 400),
        'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 2]]),
    ]);

    $player = cityDone(13204, 'JailNudgeFallback');
    $player->banned_until = now()->addDay()->startOfMinute();
    $player->save();

    $responder = TelegramResponder::forChat(app(TelegramClient::class), 13204);
    app(CharacterJailPushService::class)->nudge($player->fresh(), $responder);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendPhoto'));
    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), '(МСК)');
    });
});

it('falls back to sendMessage when jail image file is missing', function (): void {
    Config::set('app.timezone', 'Europe/Moscow');
    Config::set('bot.jail_image', storage_path('missing-jail.png'));
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 55]]),
    ]);

    $player = cityDone(13205, 'JailNoFile');
    $player->tg_chat_id = 13205;
    $player->banned_until = now()->addDay()->startOfMinute();
    $player->save();

    app(CharacterJailPushService::class)->pushJailed($player->fresh());

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), '(МСК)');
    });
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/sendPhoto'));
});

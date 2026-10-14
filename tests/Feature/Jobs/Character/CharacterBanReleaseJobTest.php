<?php

declare(strict_types=1);

use App\Jobs\Character\CharacterBanReleaseJob;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('releases ban and pushes panel when until matches and is past', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $until = now()->subMinute();
    $player = cityDone(13121, 'BanJobOk');
    $player->banned_until = $until;
    $player->tg_chat_id = 13121;
    $player->tg_message_id = 53;
    $player->save();

    app()->call([
        new CharacterBanReleaseJob($player->tg_id, $until->getTimestamp()),
        'handle',
    ]);

    expect($player->fresh()->banned_until)->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendPhoto')
            && str_contains($request->body(), 'city:home');
    });
});

it('does not release while ban is still active', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $until = now()->addDay();
    $player = characters()->createDraft(13122);
    $player->banned_until = $until;
    $player->tg_chat_id = 13122;
    $player->tg_message_id = 54;
    $player->save();

    app()->call([
        new CharacterBanReleaseJob($player->tg_id, $until->getTimestamp()),
        'handle',
    ]);

    expect($player->fresh()->banned_until)->not->toBeNull()
        ->and($player->fresh()->banned_until?->isFuture())->toBeTrue();

    Http::assertNothingSent();
});

it('does not release when banned_until timestamp was changed', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $until = now()->subMinute();
    $player = characters()->createDraft(13123);
    $player->banned_until = $until;
    $player->tg_chat_id = 13123;
    $player->tg_message_id = 55;
    $player->save();

    app()->call([
        new CharacterBanReleaseJob($player->tg_id, $until->getTimestamp() - 60),
        'handle',
    ]);

    expect($player->fresh()->banned_until)->not->toBeNull()
        ->and($player->fresh()->banned_until?->format('Y-m-d H:i'))->toBe($until->format('Y-m-d H:i'));

    Http::assertNothingSent();
});

it('no-ops when character is missing', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    app()->call([
        new CharacterBanReleaseJob(13124, now()->subMinute()->getTimestamp()),
        'handle',
    ]);

    Http::assertNothingSent();
});

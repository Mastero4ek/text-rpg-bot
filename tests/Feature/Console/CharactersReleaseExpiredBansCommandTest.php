<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('releases expired bans and pushes jail released panel', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 91]]),
    ]);

    $expired = cityDone(13301, 'BanExpCmd');
    $expired->banned_until = now()->subMinute();
    $expired->tg_chat_id = 13301;
    $expired->tg_message_id = 12;
    $expired->save();

    $active = cityDone(13302, 'BanActiveCmd');
    $active->banned_until = now()->addHour();
    $active->tg_chat_id = 13302;
    $active->save();

    $this->artisan('characters:release-expired-bans')
        ->expectsOutputToContain('Released expired bans: 1')
        ->assertSuccessful();

    expect($expired->fresh()->banned_until)->toBeNull()
        ->and($active->fresh()->banned_until)->not->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendPhoto')
            && str_contains($request->body(), 'city:home');
    });
});

it('reports zero when nobody is past ban', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $this->artisan('characters:release-expired-bans')
        ->expectsOutputToContain('Released expired bans: 0')
        ->assertSuccessful();

    Http::assertNothingSent();
});

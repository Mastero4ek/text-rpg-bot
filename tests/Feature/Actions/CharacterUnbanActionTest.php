<?php

declare(strict_types=1);

use App\Actions\Character\CharacterUnbanAction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('clears banned_until and pushes released jail panel', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $player = cityDone(13111, 'UnbanOk');
    $player->banned_until = now()->addDay();
    $player->tg_chat_id = 13111;
    $player->tg_message_id = 51;
    $player->save();

    $released = app(CharacterUnbanAction::class)->handle($player->fresh());

    expect($released->banned_until)->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendPhoto')
            && str_contains($request->body(), 'city:home');
    });
});

it('does not push when character is already without ban', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $player = cityDone(13112, 'UnbanNoop');
    $player->tg_chat_id = 13112;
    $player->tg_message_id = 52;
    $player->save();

    $released = app(CharacterUnbanAction::class)->handle($player->fresh());

    expect($released->banned_until)->toBeNull();

    Http::assertNothingSent();
});

<?php

declare(strict_types=1);

use App\Enums\Fight\FightStepEnum;
use App\Enums\ProgressStepEnum;
use App\Services\Fight\FightPanelService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

it('edits single photo caption and inline keyboard on refresh', function (): void {
    Bus::fake();
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]]),
    ]);

    $p = characters()->createDraft(9610);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'RefreshHero';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 10;
    $fight->tg_log_message_id = 11;
    $fight->tg_reply_kind = 'zones_atk';
    $fight->step = FightStepEnum::ATTACK;
    $fight->save();

    app(FightPanelService::class)->refresh(app(App\Support\Telegram\TelegramClient::class), $fight, $p);

    $fight->refresh();

    expect($fight->tg_message_id)->toBe(10)
        ->and($fight->tg_log_message_id)->toBeNull()
        ->and($fight->tg_reply_kind)->toBe('zones_atk');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/deleteMessage')
            && (int) $request['message_id'] === 11;
    });

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/editMessageCaption')) {
            return false;
        }

        $zoneJson = mb_substr(json_encode(__('combat.zone_label.HEAD'), JSON_THROW_ON_ERROR), 1, -1);
        $markup = (string) $request['reply_markup'];

        return (int) $request['message_id'] === 10
            && str_contains((string) $request['caption'], 'Раунд')
            && str_contains((string) $request['caption'], 'Выбери удар')
            && str_contains($markup, 'inline_keyboard')
            && str_contains($markup, 'fight:atk:HEAD')
            && str_contains($markup, $zoneJson);
    });

    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->url(), '/deleteMessage')
            && (int) $request['message_id'] === 10;
    });
});

it('resends status photo with inline when caption cannot be edited', function (): void {
    Bus::fake();
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/editMessageCaption')) {
            return Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => "Bad Request: message can't be edited",
            ], 400);
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => 77]]);
    });

    $p = characters()->createDraft(9611);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'ResendHero';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 10;
    $fight->tg_reply_kind = 'stance_potions';
    $fight->player_hp = 40;
    $fight->save();

    app(FightPanelService::class)->refresh(app(App\Support\Telegram\TelegramClient::class), $fight, $p);

    $fight->refresh();

    expect($fight->tg_message_id)->toBe(77)
        ->and($fight->tg_log_message_id)->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/deleteMessage')
            && (int) $request['message_id'] === 10;
    });

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/sendPhoto')) {
            return false;
        }

        $body = $request->body();

        return str_contains($body, '40/')
            && str_contains($body, 'Раунд')
            && str_contains($body, 'inline_keyboard');
    });
});

it('falls back to caption when editMessageText says there is no text', function (): void {
    Bus::fake();
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/editMessageText')) {
            return Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: there is no text in the message to edit',
            ], 400);
        }

        return Http::response(['ok' => true, 'result' => true]);
    });

    $client = app(App\Support\Telegram\TelegramClient::class);
    $client->editMessageText(1, 10, 'hello', null);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/editMessageCaption'));
});

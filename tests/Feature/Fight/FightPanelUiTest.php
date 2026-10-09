<?php

declare(strict_types=1);

use App\Enums\Fight\FightReturnEnum;
use App\Enums\ProgressStepEnum;
use App\Models\City;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Telegram\Handlers\FightHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Bus::fake();
    $id = 0;
    Http::fake(function () use (&$id) {
        $id++;

        return Http::response(['ok' => true, 'result' => ['message_id' => $id]]);
    });
});

it('starts forest fight with single photo panel and inline keyboard', function (): void {
    $p = characters()->createDraft(9501);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'PanelHero';
    $p->level = 4;
    $p = placeInCity($p, City::KEY_ANKRAT);

    $update = fightPanelCallback($p->tg_id, 'fight:start:chance_wanderer');
    app(FightHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    $fight = fights()->findByTgId($p->tg_id);
    $p->refresh();

    expect($fight->tg_message_id)->not->toBeNull()
        ->and($fight->tg_log_message_id)->toBeNull()
        ->and($fight->tg_reply_kind)->toBe('stance_potions')
        ->and($fight->return_to)->toBe(FightReturnEnum::Forest)
        ->and($p->fight_return)->toBe(FightReturnEnum::Forest);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/sendPhoto')) {
            return false;
        }

        $body = $request->body();
        $attackJson = mb_substr(json_encode(__('combat.btn_attack'), JSON_THROW_ON_ERROR), 1, -1);
        $potionJson = mb_substr(json_encode(__('combat.btn_potion_short'), JSON_THROW_ON_ERROR), 1, -1);

        return str_contains($body, '[4]')
            && str_contains($body, 'Раунд')
            && str_contains($body, $attackJson)
            && str_contains($body, $potionJson)
            && str_contains($body, 'inline_keyboard')
            && str_contains($body, 'fight:stance:ATTACK')
            && str_contains($body, 'fight:atk:POTION')
            && ! str_contains($body, 'fight:atk:HEAD');
    });
});

it('rejects forest start without hp', function (): void {
    $p = characters()->createDraft(9502);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->current_hp = 0;
    $p = placeInCity($p, City::KEY_ANKRAT);

    $update = fightPanelCallback($p->tg_id, 'fight:start:chance_wanderer');
    app(FightHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    expect(fights()->exists($p->tg_id))->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], mb_trim(__('errors.no_hp')));
    });
});

it('rejects unknown forest enemy', function (): void {
    $p = characters()->createDraft(9503);
    $p->progress_step = ProgressStepEnum::DONE;
    $p = placeInCity($p, City::KEY_ANKRAT);

    $update = fightPanelCallback($p->tg_id, 'fight:start:missing_mob');
    app(FightHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    expect(fights()->exists($p->tg_id))->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], mb_trim(__('errors.enemy_not_found')));
    });
});

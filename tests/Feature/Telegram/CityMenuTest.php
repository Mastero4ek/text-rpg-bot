<?php

declare(strict_types=1);

use App\Enums\ProgressStepEnum;
use App\Jobs\ResolveFightTurnTimeoutJob;
use App\Models\City;
use App\Models\Enemy\EnemyCatalog;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Handlers\CityHandler;
use App\Telegram\Handlers\MenuHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('does not start a fight from menu:fight', function (): void {
    $p = characters()->createDraft(9120);
    $p->progress_step = ProgressStepEnum::DONE;
    $p = placeInCity($p, City::KEY_ANKRAT);

    app(MenuHandler::class)->handleCallback(
        cityCallback($p->tg_id, 'menu:fight'),
        new TelegramResponder(app(TelegramClient::class), cityCallback($p->tg_id, 'menu:fight')),
    );

    expect(fights()->exists($p->tg_id))->toBeFalse();
});

it('opens training pick then starts hall fight without forest pivot', function (): void {
    $soldier = EnemyCatalog::query()->findOrFail(EnemyCatalog::TUTORIAL_CATALOG_ID);
    $soldier->cities()->sync([]);
    $kurgan = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
    $soldier->trainingCities()->sync([$kurgan->id]);

    $p = characters()->createDraft(9121);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'Hall';
    $p = placeInCity($p, City::KEY_ELDWOOD);

    app(CityHandler::class)->handleCallback(
        cityCallback($p->tg_id, 'city:training'),
        new TelegramResponder(app(TelegramClient::class), cityCallback($p->tg_id, 'city:training')),
    );

    expect(fights()->exists($p->tg_id))->toBeFalse();
    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageText')
            && ($request['text'] ?? null) === __('telegram.location.training')
            && str_contains((string) $request['reply_markup'], 'city:training:start:' . EnemyCatalog::TUTORIAL_CATALOG_ID);
    });

    app(CityHandler::class)->handleCallback(
        cityCallback($p->tg_id, 'city:training:start:' . EnemyCatalog::TUTORIAL_CATALOG_ID),
        new TelegramResponder(
            app(TelegramClient::class),
            cityCallback($p->tg_id, 'city:training:start:' . EnemyCatalog::TUTORIAL_CATALOG_ID),
        ),
    );

    expect(fights()->exists($p->tg_id))->toBeTrue()
        ->and(fights()->enemy(fights()->findByTgId($p->tg_id))->catalogId)->toBe(EnemyCatalog::TUTORIAL_CATALOG_ID)
        ->and(fights()->findByTgId($p->tg_id)->tutorial)->toBeFalse()
        ->and(fights()->findByTgId($p->tg_id)->hall)->toBeTrue();
});

it('does not wear gear or break gems after hall win', function (): void {
    $p = giveAndEquipStarterKnuckles(characters()->createDraft(9122));
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'HallWin';
    $p = placeInCity($p, City::KEY_ANKRAT);
    $p = grantGem($p, 'ruby_0', 1);
    $knuckles = backpack()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = bag()->socket($p, $knuckles->id, looseGem($p, 'ruby_0')->id);
    expect($socket->ok)->toBeTrue();
    $p = $socket->character;
    $knuckles->refresh();
    $knuckles->durability = 40;
    $knuckles->save();
    $durability = $knuckles->durability;

    $fight = fights()->createHall($p, woodenSoldier($p));
    $enemy = $fight->enemy;
    $enemy['current_hp'] = 0;
    $fight->enemy = $enemy;
    $fight->player_hp = 10;
    $fight->player_stamina = 12;
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 55;
    $fight->turn_deadline_at = now()->subSecond();
    $fight->save();

    fakeRandom([0.99, 0.0, 0.0]);
    app()->call([new ResolveFightTurnTimeoutJob($fight->tg_id, $fight->turn_seq), 'handle']);

    expect(fights()->exists($p->tg_id))->toBeFalse();
    $knuckles->refresh();
    expect($knuckles->durability)->toBe($durability)
        ->and(bag()->socketedInstances($knuckles)->count())->toBe(1);
});

it('shows arena submenu without creating a fight', function (): void {
    $p = characters()->createDraft(9123);
    $p->progress_step = ProgressStepEnum::DONE;
    $p = placeInCity($p, City::KEY_ANKRAT);

    app(CityHandler::class)->handleCallback(
        cityCallback($p->tg_id, 'city:arena'),
        new TelegramResponder(app(TelegramClient::class), cityCallback($p->tg_id, 'city:arena')),
    );

    expect(fights()->exists($p->tg_id))->toBeFalse();
    Http::assertSent(function (Request $request): bool {
        $arena = __('telegram.location.arena');

        if (str_contains($request->url(), '/editMessageMedia')) {
            return str_contains($request->body(), str_replace("\n", '\\n', $arena))
                || str_contains($request->body(), $arena);
        }

        return (str_contains($request->url(), '/editMessageText')
                || str_contains($request->url(), '/editMessageCaption'))
            && (($request['text'] ?? $request['caption'] ?? null) === $arena);
    });
});

it('resumes home panel on done /start without welcome message', function (): void {
    $p = characters()->createDraft(9124);
    $p->username = 'DoneHero';
    $p->progress_step = ProgressStepEnum::DONE;
    $p->tg_chat_id = $p->tg_id;
    $p->tg_message_id = 9;
    $p = placeInCity($p, City::KEY_ANKRAT);

    $update = new TelegramUpdate([
        'update_id' => 9124,
        'message' => [
            'message_id' => 1,
            'text' => '/start',
            'from' => ['id' => $p->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => $p->tg_id, 'type' => 'private'],
        ],
    ]);

    app(App\Telegram\Handlers\RegistrationHandler::class)->handleStart(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'С возвращением');
    });
    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageMedia')
            || str_contains($request->url(), '/editMessageCaption')
            || str_contains($request->url(), '/editMessageText');
    });
});

it('sends splash keyboard on registration start', function (): void {
    $update = new TelegramUpdate([
        'update_id' => 9125,
        'message' => [
            'message_id' => 1,
            'text' => '/start',
            'from' => ['id' => 9125, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => 9125, 'type' => 'private'],
        ],
    ]);

    app(App\Telegram\Handlers\RegistrationHandler::class)->handleStart(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/sendPhoto')) {
            return false;
        }

        $body = $request->body();

        return str_contains($body, 'ob:rise')
            && str_contains($body, mb_trim(__('telegram.registration.splash')));
    });
});

it('exposes bot command descriptions from lang', function (): void {
    expect(__('telegram.commands.character'))->toBe('Персонаж')
        ->and(__('telegram.commands.skills'))->toBe('Навыки')
        ->and(__('telegram.commands.backpack'))->toBe('Рюкзак')
        ->and(__('telegram.commands.bag'))->toBe('Сумка');
});

function cityCallback(int $tgId, string $data): TelegramUpdate
{
    return new TelegramUpdate([
        'update_id' => $tgId,
        'callback_query' => [
            'id' => 'cb-' . $tgId . '-' . $data,
            'data' => $data,
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 9,
                'chat' => ['id' => $tgId, 'type' => 'private'],
                'text' => 'city',
            ],
        ],
    ]);
}

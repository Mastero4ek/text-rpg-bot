<?php

declare(strict_types=1);

use App\Enums\OnboardingStepEnum;
use App\Jobs\ResolveFightTurnTimeoutJob;
use App\Models\City;
use App\Models\Enemy\EnemyCatalog;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Handlers\CityHandler;
use App\Telegram\Handlers\MenuHandler;
use App\Telegram\Keyboards\TelegramKeyboards;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('does not start a fight from menu:fight', function (): void {
    $p = characters()->createDraft(9120);
    $p->onboarding_step = OnboardingStepEnum::DONE;
    $p = placeInCity($p, City::KEY_YASEN);

    app(MenuHandler::class)->handleCallback(
        cityCallback($p->tg_id, 'menu:fight'),
        new TelegramResponder(app(TelegramClient::class), cityCallback($p->tg_id, 'menu:fight')),
    );

    expect(fights()->exists($p->tg_id))->toBeFalse();
});

it('starts hall fight with wooden_soldier without forest pivot', function (): void {
    EnemyCatalog::query()->findOrFail(EnemyCatalog::TUTORIAL_CATALOG_ID)->cities()->sync([]);
    $p = characters()->createDraft(9121);
    $p->onboarding_step = OnboardingStepEnum::DONE;
    $p->username = 'Hall';
    $p = placeInCity($p, City::KEY_KURGAN);

    app(CityHandler::class)->handleCallback(
        cityCallback($p->tg_id, 'city:training'),
        new TelegramResponder(app(TelegramClient::class), cityCallback($p->tg_id, 'city:training')),
    );

    expect(fights()->exists($p->tg_id))->toBeTrue()
        ->and(fights()->enemy(fights()->findByTgId($p->tg_id))->catalogId)->toBe(EnemyCatalog::TUTORIAL_CATALOG_ID)
        ->and(fights()->findByTgId($p->tg_id)->tutorial)->toBeFalse();
});

it('does not wear gear or break gems after hall win', function (): void {
    $p = giveAndEquipStarterKnuckles(characters()->createDraft(9122));
    $p->onboarding_step = OnboardingStepEnum::DONE;
    $p->username = 'HallWin';
    $p = placeInCity($p, City::KEY_YASEN);
    $p = grantGem($p, 'ruby_0', 1);
    $knuckles = backpack()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = bag()->socket($p, $knuckles->id, looseGem($p, 'ruby_0')->id);
    expect($socket->ok)->toBeTrue();
    $p = $socket->character;
    $knuckles->refresh();
    $knuckles->durability = 40;
    $knuckles->save();
    $durability = $knuckles->durability;

    $fight = fights()->createTraining($p, woodenSoldier($p));
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

it('shows arena stub without creating a fight', function (): void {
    $p = characters()->createDraft(9123);
    $p->onboarding_step = OnboardingStepEnum::DONE;
    $p = placeInCity($p, City::KEY_YASEN);

    app(CityHandler::class)->handleCallback(
        cityCallback($p->tg_id, 'city:arena'),
        new TelegramResponder(app(TelegramClient::class), cityCallback($p->tg_id, 'city:arena')),
    );

    expect(fights()->exists($p->tg_id))->toBeFalse();
    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageText')
            && ($request['text'] ?? null) === __('city.arena_stub');
    });
});

it('sends personal reply keyboard after done /start', function (): void {
    $p = characters()->createDraft(9124);
    $p->username = 'DoneHero';
    $p->onboarding_step = OnboardingStepEnum::DONE;
    $p = placeInCity($p, City::KEY_YASEN);

    $update = new TelegramUpdate([
        'update_id' => 9124,
        'message' => [
            'message_id' => 1,
            'text' => '/start',
            'from' => ['id' => $p->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => $p->tg_id, 'type' => 'private'],
        ],
    ]);

    app(App\Telegram\Handlers\OnboardingHandler::class)->handleStart(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/sendMessage')) {
            return false;
        }

        $markup = $request['reply_markup'] ?? '';

        if (! is_string($markup)) {
            return false;
        }

        return str_contains($markup, 'resize_keyboard');
    });
});

it('removes reply keyboard on onboarding nick prompt', function (): void {
    $update = new TelegramUpdate([
        'update_id' => 9125,
        'message' => [
            'message_id' => 1,
            'text' => '/start',
            'from' => ['id' => 9125, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => 9125, 'type' => 'private'],
        ],
    ]);

    app(App\Telegram\Handlers\OnboardingHandler::class)->handleStart(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/sendMessage')) {
            return false;
        }

        $markup = $request['reply_markup'] ?? '';

        if (! is_string($markup)) {
            return false;
        }

        return str_contains($markup, 'remove_keyboard');
    });
});

it('exposes personal reply labels from lang', function (): void {
    $markup = TelegramKeyboards::personalReply();

    expect($markup['keyboard'][0][0]['text'])->toBe(__('menu.profile'))
        ->and($markup['keyboard'][0][1]['text'])->toBe(__('menu.inv'))
        ->and($markup['keyboard'][0][2]['text'])->toBe(__('menu.stats'));
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

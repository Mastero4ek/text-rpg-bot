<?php

declare(strict_types=1);

use App\Enums\ProgressStepEnum;
use App\Jobs\IdleSessionResetJob;
use App\Models\Character;
use App\Models\City;
use App\Services\Telegram\IdleSessionService;
use App\Support\LangVariant;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Handlers\CityHandler;
use App\Telegram\UpdateProcessor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

/**
 * @return array{0: Character, 1: City}
 */
function idleReadyPlayer(int $tgId): array
{
    $p = characters()->createDraft($tgId);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'Idle' . $tgId;
    $p = placeInCity($p, City::KEY_ANKRAT);
    $p->tg_chat_id = $tgId;
    $p->tg_message_id = 77;
    $p->save();

    $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();

    return [$p, $city];
}

function idlePortalEditSent(string $cityName): bool
{
    $btn = __('telegram.btn.idle_to_city', ['name' => $cityName]);
    $variants = LangVariant::all('telegram.city.idle');

    $matched = false;

    Http::assertSent(function (Request $request) use ($btn, $variants, &$matched): bool {
        if (! str_contains($request->url(), '/editMessageMedia')) {
            return false;
        }

        $body = $request->body();

        if (! str_contains($body, 'portal_session.png')) {
            return false;
        }

        if (! str_contains($body, 'city:home') || ! str_contains($body, $btn)) {
            return false;
        }

        if (! str_contains($body, 'success')) {
            return false;
        }

        foreach ($variants as $variant) {
            if (str_contains($body, $variant) || str_contains($body, str_replace("\n", '\\n', $variant))) {
                $matched = true;

                return true;
            }
        }

        return false;
    });

    return $matched;
}

it('touch stores last_action_at and dispatches idle reset job', function (): void {
    Bus::fake([IdleSessionResetJob::class]);
    [$p] = idleReadyPlayer(9401);

    $this->freezeTime();

    $touched = app(IdleSessionService::class)->touch($p);

    expect($touched->last_action_at?->getTimestamp())->toBe(now()->getTimestamp());

    Bus::assertDispatched(IdleSessionResetJob::class, function (IdleSessionResetJob $job) use ($p): bool {
        return $job->tgId === $p->tg_id
            && $job->actionAtUnix === now()->getTimestamp();
    });
});

it('webhook callback refreshes last_action_at and schedules idle job', function (): void {
    Bus::fake([IdleSessionResetJob::class]);
    [$p] = idleReadyPlayer(9402);

    $this->freezeTime();

    cityFlowCallback($p->tg_id, 77, 'city:tavern');

    $fresh = $p->fresh();

    expect($fresh)->toBeInstanceOf(Character::class)
        ->and($fresh->last_action_at?->getTimestamp())->toBe(now()->getTimestamp());

    Bus::assertDispatched(IdleSessionResetJob::class, function (IdleSessionResetJob $job) use ($p): bool {
        return $job->tgId === $p->tg_id
            && $job->actionAtUnix === now()->getTimestamp();
    });
});

it('idle job edits anchored panel into session portal with return button', function (): void {
    Bus::fake([IdleSessionResetJob::class]);
    [$p, $city] = idleReadyPlayer(9403);

    $this->freezeTime();
    app(IdleSessionService::class)->touch($p);
    $actionAtUnix = $p->fresh()->last_action_at?->getTimestamp();
    expect($actionAtUnix)->toBeInt();

    $this->travel(601)->seconds();

    app()->call([new IdleSessionResetJob($p->tg_id, $actionAtUnix), 'handle']);

    expect(idlePortalEditSent($city->name))->toBeTrue()
        ->and($p->fresh()->last_action_at?->getTimestamp())->toBe(now()->getTimestamp());
});

it('city home after idle edits the same panel into hub', function (): void {
    Bus::fake([IdleSessionResetJob::class]);
    [$p] = idleReadyPlayer(9404);

    $update = new TelegramUpdate([
        'update_id' => $p->tg_id,
        'callback_query' => [
            'id' => 'cb-idle-home',
            'data' => 'city:home',
            'from' => ['id' => $p->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 77,
                'chat' => ['id' => $p->tg_id, 'type' => 'private'],
                'photo' => [['file_id' => 'idle-photo']],
                'caption' => 'idle',
            ],
        ],
    ]);

    app(CityHandler::class)->home(
        new TelegramResponder(app(TelegramClient::class), $update),
        $p,
    );

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/editMessageMedia')
            && ! str_contains($request->url(), '/editMessageCaption')) {
            return false;
        }

        $body = $request->body();
        $markup = $request['reply_markup'] ?? $body;

        return str_contains((string) $markup, 'city:gates')
            && str_contains((string) $markup, 'city:tavern')
            && ! str_contains((string) $markup, 'city:home');
    });
});

it('lazy update edits idle panel when last_action_at already expired', function (): void {
    Bus::fake([IdleSessionResetJob::class]);
    [$p, $city] = idleReadyPlayer(9405);

    $p->last_action_at = now()->subSeconds(601);
    $p->save();

    cityFlowCallback($p->tg_id, 77, 'city:tavern');

    expect(idlePortalEditSent($city->name))->toBeTrue();
});

it('idle job no-ops when action token is stale after a newer touch', function (): void {
    Bus::fake([IdleSessionResetJob::class]);
    [$p] = idleReadyPlayer(9406);

    $this->freezeTime();
    app(IdleSessionService::class)->touch($p);
    $staleUnix = now()->getTimestamp();

    $this->travel(10)->seconds();
    app(IdleSessionService::class)->touch($p->fresh());

    $this->travel(601)->seconds();
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    app()->call([new IdleSessionResetJob($p->tg_id, $staleUnix), 'handle']);

    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageMedia')
            && str_contains($request->body(), 'portal_session.png');
    });
});

it('idle job edits fight panel and clears active fight', function (): void {
    Bus::fake([IdleSessionResetJob::class]);
    [$p, $city] = idleReadyPlayer(9407);

    $this->freezeTime();
    app(IdleSessionService::class)->touch($p);
    $actionAtUnix = now()->getTimestamp();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 88;
    $fight->save();

    $this->travel(601)->seconds();
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    app()->call([new IdleSessionResetJob($p->tg_id, $actionAtUnix), 'handle']);

    expect(idlePortalEditSent($city->name))->toBeTrue()
        ->and(fights()->exists($p->tg_id))->toBeFalse();

    $p->refresh();
    expect($p->tg_message_id)->toBe(88);
});

it('idle job no-ops during registration', function (): void {
    Bus::fake([IdleSessionResetJob::class]);

    $p = characters()->createDraft(9408);
    $p->progress_step = ProgressStepEnum::SET_NICK;
    $p->tg_chat_id = $p->tg_id;
    $p->tg_message_id = 77;
    $p->last_action_at = now()->subSeconds(601);
    $p->save();

    app()->call([new IdleSessionResetJob($p->tg_id, $p->last_action_at->getTimestamp()), 'handle']);

    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageMedia')
            && str_contains($request->body(), 'portal_session.png');
    });
});

it('idle job no-ops for banned character', function (): void {
    Bus::fake([IdleSessionResetJob::class]);
    [$p] = idleReadyPlayer(9409);

    $p->banned_until = now()->addDay();
    $p->last_action_at = now()->subSeconds(601);
    $p->save();

    app()->call([new IdleSessionResetJob($p->tg_id, $p->last_action_at->getTimestamp()), 'handle']);

    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageMedia')
            && str_contains($request->body(), 'portal_session.png');
    });
});

it('idle job no-ops when telegram panel anchor is missing', function (): void {
    Bus::fake([IdleSessionResetJob::class]);
    [$p] = idleReadyPlayer(9410);

    $p->tg_chat_id = null;
    $p->tg_message_id = null;
    $p->last_action_at = now()->subSeconds(601);
    $p->save();

    app()->call([new IdleSessionResetJob($p->tg_id, $p->last_action_at->getTimestamp()), 'handle']);

    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageMedia')
            || str_contains($request->url(), '/sendPhoto');
    });
});

it('idle job no-ops when deadline has not passed yet', function (): void {
    Bus::fake([IdleSessionResetJob::class]);
    [$p] = idleReadyPlayer(9411);

    $this->freezeTime();
    app(IdleSessionService::class)->touch($p);
    $actionAtUnix = now()->getTimestamp();

    $this->travel(60)->seconds();
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    app()->call([new IdleSessionResetJob($p->tg_id, $actionAtUnix), 'handle']);

    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageMedia')
            && str_contains($request->body(), 'portal_session.png');
    });
});

it('shouldNotify is false when last_action_at is null', function (): void {
    [$p] = idleReadyPlayer(9412);
    $p->last_action_at = null;
    $p->save();

    expect(app(IdleSessionService::class)->shouldNotify($p))->toBeFalse();
});

it('does not schedule idle when update belongs to unknown character', function (): void {
    Bus::fake([IdleSessionResetJob::class]);

    app(UpdateProcessor::class)->handle([
        'update_id' => 94130,
        'message' => [
            'message_id' => 1,
            'text' => 'hello',
            'from' => ['id' => 94130, 'is_bot' => false, 'first_name' => 'X'],
            'chat' => ['id' => 94130, 'type' => 'private'],
        ],
    ]);

    Bus::assertNotDispatched(IdleSessionResetJob::class);
});

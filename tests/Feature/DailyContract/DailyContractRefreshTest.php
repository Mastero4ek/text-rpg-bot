<?php

declare(strict_types=1);

use App\Enums\DailyContract\DailyContractStatusEnum;
use App\Enums\ProgressStepEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Jobs\DailyContract\DailyContractBoardPushJob;
use App\Jobs\DailyContract\DailyContractRefreshCharacterJob;
use App\Models\Character\Character;
use App\Models\Character\CharacterDailyContract;
use App\Models\City;
use App\Services\DailyContract\DailyContractBoardPushService;
use App\Services\DailyContract\DailyContractRefreshService;
use App\Support\LangVariant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 501]]),
    ]);
});

function refreshPlayer(int $tgId, string $nick): Character
{
    $player = dailyPlayer($tgId, $nick);
    $player->tg_chat_id = $tgId;
    $player->tg_message_id = 77;
    $player->save();

    return $player->fresh();
}

function refreshService(): DailyContractRefreshService
{
    return app(DailyContractRefreshService::class);
}

it('dispatches refresh jobs for eligible characters from the artisan command', function (): void {
    Queue::fake();

    $eligible = refreshPlayer(98001, 'RefCmdOk');
    $locked = refreshPlayer(98002, 'RefCmdLock');
    $locked->unlock_board = false;
    $locked->save();

    $this->artisan('daily-contracts:refresh')
        ->expectsOutputToContain('Dispatched daily contract refresh jobs:')
        ->assertSuccessful();

    Queue::assertPushed(DailyContractRefreshCharacterJob::class, function (DailyContractRefreshCharacterJob $job) use ($eligible): bool {
        return $job->tgId === $eligible->tg_id;
    });
    Queue::assertNotPushed(DailyContractRefreshCharacterJob::class, function (DailyContractRefreshCharacterJob $job) use ($locked): bool {
        return $job->tgId === $locked->tg_id;
    });
});

it('materializes three offers and queues a board push when missing', function (): void {
    Queue::fake();

    $player = refreshPlayer(98010, 'RefMat');

    $created = refreshService()->refreshCharacter($player->tg_id);

    expect($created)->toBeTrue()
        ->and(CharacterDailyContract::query()->where('tg_id', $player->tg_id)->count())->toBe(gameConfig()->dailyContracts()['board_slots']);

    Queue::assertPushed(DailyContractBoardPushJob::class, function (DailyContractBoardPushJob $job) use ($player): bool {
        return $job->tgId === $player->tg_id;
    });
});

it('does not rematerialize or push twice on the same board date', function (): void {
    Queue::fake();

    $player = refreshPlayer(98011, 'RefIdem');
    $first = refreshService()->refreshCharacter($player->tg_id);

    expect($first)->toBeTrue()
        ->and(CharacterDailyContract::query()->where('tg_id', $player->tg_id)->count())->toBe(gameConfig()->dailyContracts()['board_slots']);

    Queue::assertPushed(DailyContractBoardPushJob::class);
    Queue::fake();

    $again = refreshService()->refreshCharacter($player->tg_id);

    expect($again)->toBeFalse();
    Queue::assertNothingPushed();
});

it('still queues one push when offers were already materialized lazily', function (): void {
    Queue::fake();

    $player = refreshPlayer(98017, 'RefLazyPush');
    dailyService()->ensureOffers($player, dailyCity($player));

    expect(CharacterDailyContract::query()->where('tg_id', $player->tg_id)->count())->toBe(gameConfig()->dailyContracts()['board_slots']);

    $pushed = refreshService()->refreshCharacter($player->tg_id);

    expect($pushed)->toBeTrue();
    Queue::assertPushed(DailyContractBoardPushJob::class, function (DailyContractBoardPushJob $job) use ($player): bool {
        return $job->tgId === $player->tg_id;
    });
});

it('skips banned characters', function (): void {
    Queue::fake();

    $player = refreshPlayer(98012, 'RefBan');
    $player->banned_until = Date::now()->addDay();
    $player->save();

    expect(refreshService()->isEligible($player->fresh()))->toBeFalse()
        ->and(refreshService()->refreshCharacter($player->tg_id))->toBeFalse()
        ->and(CharacterDailyContract::query()->where('tg_id', $player->tg_id)->exists())->toBeFalse();

    Queue::assertNothingPushed();
});

it('skips characters before quest phase Done', function (): void {
    Queue::fake();

    $player = refreshPlayer(98013, 'RefPhase');
    $player->quest_phase = QuestPhaseEnum::Buyer;
    $player->save();

    expect(refreshService()->refreshCharacter($player->tg_id))->toBeFalse()
        ->and(CharacterDailyContract::query()->where('tg_id', $player->tg_id)->exists())->toBeFalse();
});

it('skips characters without unlock_board', function (): void {
    Queue::fake();

    $player = refreshPlayer(98014, 'RefBoard');
    $player->unlock_board = false;
    $player->save();

    expect(refreshService()->refreshCharacter($player->tg_id))->toBeFalse();
});

it('skips characters without telegram chat id', function (): void {
    Queue::fake();

    $player = refreshPlayer(98015, 'RefNoChat');
    $player->tg_chat_id = null;
    $player->save();

    expect(refreshService()->isEligible($player->fresh()))->toBeFalse()
        ->and(refreshService()->refreshCharacter($player->tg_id))->toBeFalse();
});

it('skips when current city has no quest board', function (): void {
    Queue::fake();

    $player = refreshPlayer(98016, 'RefNoBoardCity');
    $city = City::query()->findOrFail($player->city_id);
    $city->has_quest_board = false;
    $city->save();

    expect(refreshService()->refreshCharacter($player->tg_id))->toBeFalse()
        ->and(CharacterDailyContract::query()->where('tg_id', $player->tg_id)->exists())->toBeFalse();
});

it('edits the character anchor when pushing outside a fight', function (): void {
    $player = refreshPlayer(98020, 'RefPushEdit');
    $city = City::query()->findOrFail($player->city_id);

    app(DailyContractBoardPushService::class)->notify($player);

    $btn = __('telegram.btn.idle_to_city', ['name' => $city->name]);
    $variants = LangVariant::all('telegram.daily.board_refreshed');

    Http::assertSent(function (Request $request) use ($player, $btn, $variants, $city): bool {
        if (! str_contains($request->url(), '/editMessageMedia')) {
            return false;
        }

        $body = $request->body();

        if (! str_contains($body, 'quest_board.png')) {
            return false;
        }

        if (! str_contains($body, (string) $player->tg_message_id)) {
            return false;
        }

        if (! str_contains($body, 'city:home') || ! str_contains($body, $btn)) {
            return false;
        }

        foreach ($variants as $variant) {
            $caption = str_replace(':city', $city->name, $variant);

            if (str_contains($body, $caption) || str_contains($body, str_replace("\n", '\\n', $caption))) {
                return true;
            }
        }

        return false;
    });

    expect($player->fresh()->tg_message_id)->toBe(77);
});

it('sends a detached message during a fight without moving the character anchor', function (): void {
    $player = refreshPlayer(98021, 'RefPushFight');
    fights()->createTraining($player, enemyFromCatalog('forest_bandit', $player));

    expect(fights()->exists($player->tg_id))->toBeTrue();

    app(DailyContractBoardPushService::class)->notify($player->fresh());

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendPhoto')
            && str_contains($request->body(), 'quest_board.png')
            && str_contains($request->body(), 'city:home');
    });

    Http::assertNotSent(function (Request $request) use ($player): bool {
        return str_contains($request->url(), '/editMessageMedia')
            && str_contains($request->body(), (string) $player->tg_message_id);
    });

    expect($player->fresh()->tg_message_id)->toBe(77);
});

it('schedules daily-contracts refresh at 08:00', function (): void {
    $schedule = app(Illuminate\Console\Scheduling\Schedule::class);
    $matched = false;

    foreach ($schedule->events() as $event) {
        $summary = $event->command ?? $event->getSummaryForDisplay();

        if (! is_string($summary) || ! str_contains($summary, 'daily-contracts:refresh')) {
            continue;
        }

        expect($event->expression)->toBe('0 8 * * *');
        $matched = true;
    }

    expect($matched)->toBeTrue();
});

it('keeps offered status after materialize so the board can be taken', function (): void {
    Queue::fake();

    $player = refreshPlayer(98030, 'RefOffered');

    (new DailyContractRefreshCharacterJob($player->tg_id))->handle(refreshService());

    $statuses = CharacterDailyContract::query()
        ->where('tg_id', $player->tg_id)
        ->pluck('status')
        ->all();

    expect($statuses)->toHaveCount(gameConfig()->dailyContracts()['board_slots']);

    foreach ($statuses as $status) {
        expect($status)->toBe(DailyContractStatusEnum::Offered);
    }
});
it('does not treat mid-registration progress as eligible', function (): void {
    $player = refreshPlayer(98031, 'RefReg');
    $player->progress_step = ProgressStepEnum::SET_NICK;
    $player->save();

    expect(refreshService()->isEligible($player->fresh()))->toBeFalse();
});

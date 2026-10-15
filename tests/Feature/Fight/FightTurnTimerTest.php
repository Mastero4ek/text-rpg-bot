<?php

declare(strict_types=1);

use App\Enums\Combat\StanceEnum;
use App\Enums\ProgressStepEnum;
use App\Jobs\FightTurnWatchJob;
use App\Services\Fight\FightStatusFormatter;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Telegram\Handlers\FightHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

it('sets deadline and dispatches first watch on fight create', function (): void {
    Bus::fake([FightTurnWatchJob::class]);
    $this->freezeTime();

    $p = characters()->createDraft(9701);
    $fight = fights()->createTraining($p, woodenSoldier($p));
    $timeout = fights()->turnTimeoutSeconds();
    $tick = fights()->turnTickSeconds();

    expect($fight->turn_deadline_at?->getTimestamp())->toBe(now()->addSeconds($timeout)->getTimestamp())
        ->and(fights()->turnTimerDisplaySeconds($fight))->toBe($timeout)
        ->and(fights()->turnWatchDelayMs($fight))->toBe($tick * 1000);

    Bus::assertDispatched(FightTurnWatchJob::class, function (FightTurnWatchJob $job) use ($fight): bool {
        return $job->tgId === $fight->tg_id
            && $job->turnSeq === $fight->turn_seq;
    });
});

it('snaps timer display to tick grid when worker is late', function (): void {
    Bus::fake([FightTurnWatchJob::class]);
    $this->freezeTime();

    $p = characters()->createDraft(9702);
    $fight = fights()->createTraining($p, woodenSoldier($p));
    $timeout = fights()->turnTimeoutSeconds();
    $tick = fights()->turnTickSeconds();

    $this->travel($tick + 2)->seconds();

    expect(fights()->turnTimerDisplaySeconds($fight))->toBe($timeout - $tick)
        ->and(fights()->turnWatchDelayMs($fight))->toBe(($tick - 2) * 1000);
});

it('renders grid timer in panel caption', function (): void {
    Bus::fake([FightTurnWatchJob::class]);
    $this->freezeTime();

    $p = characters()->createDraft(9703);
    $p->username = 'TimerHero';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $timeout = fights()->turnTimeoutSeconds();
    $tick = fights()->turnTickSeconds();

    $panel = app(FightStatusFormatter::class)->panelCaption($fight, $p);

    expect($panel)->toContain(__('combat.turn_timer_sec', ['seconds' => $timeout]));

    $this->travel($tick)->seconds();
    $panel = app(FightStatusFormatter::class)->panelCaption($fight->fresh(), $p);

    expect($panel)->toContain(__('combat.turn_timer_sec', ['seconds' => $timeout - $tick]))
        ->and($panel)->not->toContain(__('combat.turn_timer_sec', ['seconds' => $timeout - $tick - 1]));
});

it('watch job refreshes caption on tick and redispatches next absolute delay', function (): void {
    Bus::fake([FightTurnWatchJob::class]);
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]]),
    ]);
    $this->freezeTime();

    $p = characters()->createDraft(9704);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'TickHero';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 40;
    $fight->save();

    $timeout = fights()->turnTimeoutSeconds();
    $tick = fights()->turnTickSeconds();
    $seq = $fight->turn_seq;

    $this->travel($tick)->seconds();

    app()->call([new FightTurnWatchJob($fight->tg_id, $seq), 'handle']);

    Http::assertSent(function (Request $request) use ($timeout, $tick): bool {
        if (! str_contains($request->url(), '/editMessageCaption')) {
            return false;
        }

        $caption = (string) $request['caption'];

        return (int) $request['message_id'] === 40
            && str_contains($caption, __('combat.turn_timer_sec', ['seconds' => $timeout - $tick]));
    });

    Bus::assertDispatched(FightTurnWatchJob::class, function (FightTurnWatchJob $job) use ($fight, $seq): bool {
        return $job->tgId === $fight->tg_id && $job->turnSeq === $seq;
    });
});

it('watch job skips the turn when deadline has passed', function (): void {
    Bus::fake([FightTurnWatchJob::class]);
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]]),
    ]);
    fakeRandom([0.99, 0.99, 0.99, 0.99, 0.99, 0.99]);
    $this->freezeTime();

    $p = characters()->createDraft(9705);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'SkipHero';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 41;
    $fight->save();

    $beforeHp = $fight->player_hp;
    $beforeSeq = $fight->turn_seq;
    $timeout = fights()->turnTimeoutSeconds();

    $this->travel($timeout)->seconds();

    app()->call([new FightTurnWatchJob($fight->tg_id, $beforeSeq), 'handle']);

    $fight = fights()->findByTgId($p->tg_id);

    expect($fight->log)->toContain(__('combat.turn_timeout', [
        'name' => combat()->logName('SkipHero', $p->level),
    ]))
        ->and($fight->player_hp)->toBeLessThanOrEqual($beforeHp)
        ->and($fight->turn_seq)->toBe($beforeSeq + 1)
        ->and($fight->step->value)->toBe('STANCE');
});

it('returns zero timer display and delay without deadline', function (): void {
    Bus::fake([FightTurnWatchJob::class]);

    $p = characters()->createDraft(9706);
    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->turn_deadline_at = null;
    $fight->save();

    expect(fights()->turnTimerDisplaySeconds($fight))->toBe(0)
        ->and(fights()->turnWatchDelayMs($fight))->toBe(0);
});

it('returns zero timer display and delay after deadline', function (): void {
    Bus::fake([FightTurnWatchJob::class]);
    $this->freezeTime();

    $p = characters()->createDraft(9707);
    $fight = fights()->createTraining($p, woodenSoldier($p));

    $this->travel(fights()->turnTimeoutSeconds() + 1)->seconds();

    expect(fights()->turnTimerDisplaySeconds($fight))->toBe(0)
        ->and(fights()->turnWatchDelayMs($fight))->toBe(0);
});

it('watch job no-ops on stale turn_seq', function (): void {
    Bus::fake([FightTurnWatchJob::class]);
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]]),
    ]);
    $this->freezeTime();

    $p = characters()->createDraft(9708);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 42;
    $fight->save();

    $seq = $fight->turn_seq;
    $this->travel(fights()->turnTickSeconds())->seconds();

    app()->call([new FightTurnWatchJob($fight->tg_id, $seq + 1), 'handle']);

    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageCaption')
            || str_contains($request->url(), '/editMessageText');
    });

    expect(fights()->findByTgId($p->tg_id)->turn_seq)->toBe($seq)
        ->and(fights()->findByTgId($p->tg_id)->log)->toBe([]);
});

it('watch job no-ops when fight is missing', function (): void {
    Bus::fake([FightTurnWatchJob::class]);
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]]),
    ]);

    app()->call([new FightTurnWatchJob(9709, 1), 'handle']);

    Http::assertNothingSent();
});

it('applies skip and ignores stance click after deadline', function (): void {
    Bus::fake([FightTurnWatchJob::class]);
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]]),
    ]);
    fakeRandom([0.99, 0.99, 0.99, 0.99, 0.99, 0.99]);
    $this->freezeTime();

    $p = characters()->createDraft(9710);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'LateClick';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 43;
    $fight->save();

    $beforeSeq = $fight->turn_seq;
    $this->travel(fights()->turnTimeoutSeconds() + 1)->seconds();

    app(FightHandler::class)->handleCallback(
        fightInlineCallback($p->tg_id, 'fight:stance:ATTACK'),
        new TelegramResponder(app(TelegramClient::class), fightInlineCallback($p->tg_id, 'fight:stance:ATTACK')),
    );

    $fight = fights()->findByTgId($p->tg_id);

    expect($fight->player_stance)->toBeNull()
        ->and($fight->step->value)->toBe('STANCE')
        ->and($fight->turn_seq)->toBe($beforeSeq + 1)
        ->and($fight->log)->toContain(__('combat.turn_timeout', [
            'name' => combat()->logName('LateClick', $p->level),
        ]));
});

it('applies stance click while deadline is still ahead', function (): void {
    Bus::fake([FightTurnWatchJob::class]);
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]]),
    ]);
    $this->freezeTime();

    $p = characters()->createDraft(9711);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'OnTime';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $fight->tg_chat_id = $p->tg_id;
    $fight->tg_message_id = 44;
    $fight->save();

    $beforeSeq = $fight->turn_seq;

    app(FightHandler::class)->handleCallback(
        fightInlineCallback($p->tg_id, 'fight:stance:DEFEND'),
        new TelegramResponder(app(TelegramClient::class), fightInlineCallback($p->tg_id, 'fight:stance:DEFEND')),
    );

    $fight = fights()->findByTgId($p->tg_id);

    expect($fight->player_stance)->toBe(StanceEnum::DEFEND)
        ->and($fight->step->value)->toBe('ATTACK')
        ->and($fight->turn_seq)->toBe($beforeSeq)
        ->and($fight->log)->toBe([]);
});

it('schedules next delay to the absolute tick boundary after a late worker', function (): void {
    Bus::fake([FightTurnWatchJob::class]);
    $this->freezeTime();

    $p = characters()->createDraft(9712);
    $fight = fights()->createTraining($p, woodenSoldier($p));
    $tick = fights()->turnTickSeconds();

    $this->travel(($tick * 2) + 1)->seconds();

    expect(fights()->turnTimerDisplaySeconds($fight))->toBe(fights()->turnTimeoutSeconds() - ($tick * 2))
        ->and(fights()->turnWatchDelayMs($fight))->toBe(($tick - 1) * 1000);
});

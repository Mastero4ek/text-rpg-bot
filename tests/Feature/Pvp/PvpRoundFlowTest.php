<?php

declare(strict_types=1);

use App\Actions\Pvp\PvpChallengeAcceptAction;
use App\Actions\Pvp\PvpChallengeCreateAction;
use App\Enums\Combat\StanceEnum;
use App\Enums\Combat\ZoneEnum;
use App\Enums\Fight\FightKindEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\Fight\PlayerAttackEnum;
use App\Enums\Pvp\PvpDuelStatusEnum;
use App\Models\Fight;
use App\Models\Pvp\PvpChallenge;
use App\Models\Pvp\PvpDuel;
use App\Services\Fight\FightEndService;
use App\Services\Fight\FightStatusFormatter;
use App\Services\Pvp\PvpRoundService;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Telegram\Handlers\FightHandler;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Bus::fake();
    $id = 100;
    Http::fake(function () use (&$id) {
        $id++;

        return Http::response(['ok' => true, 'result' => ['message_id' => $id]]);
    });
});

/**
 * @return array{
 *     a: App\Models\Character\Character,
 *     b: App\Models\Character\Character,
 *     duel: PvpDuel,
 *     fightA: Fight,
 *     fightB: Fight
 * }
 */
function pvpDuelPair(int $aId, int $bId): array
{
    $a = cityDone($aId, 'DuA' . $aId);
    $a->tg_chat_id = $aId;
    $a->arena_points = 50;
    $a->save();

    $b = cityDone($bId, 'DuB' . $bId);
    $b->tg_chat_id = $bId;
    $b->arena_points = 50;
    $b->save();

    app(PvpChallengeCreateAction::class)->handle($a->fresh());
    $challenge = PvpChallenge::query()->where('challenger_tg_id', $a->tg_id)->firstOrFail();
    $result = app(PvpChallengeAcceptAction::class)->handle($b->fresh(), $challenge->id);

    expect($result['ok'])->toBeTrue();

    return [
        'a' => $a->fresh(),
        'b' => $b->fresh(),
        'duel' => $result['duel'],
        'fightA' => $result['fightA'],
        'fightB' => $result['fightB'],
    ];
}

function armPvpChoice(Fight $fight): Fight
{
    $fight->player_stance = StanceEnum::ATTACK;
    $fight->player_attack = PlayerAttackEnum::HEAD;
    $fight->player_defend = ZoneEnum::LEGS;
    $fight->step = FightStepEnum::DEFEND;
    $fight->save();

    return $fight->fresh();
}

it('keeps waiting until the second fighter commits', function (): void {
    $pair = pvpDuelPair(88101, 88102);
    armPvpChoice($pair['fightA']);

    $outcome = app(PvpRoundService::class)->submitReadyChoice($pair['a']);

    expect($outcome->kind)->toBe('waiting')
        ->and($pair['duel']->fresh()->a_choice)->not->toBeNull()
        ->and($pair['duel']->fresh()->b_choice)->toBeNull()
        ->and($pair['fightA']->fresh()->step)->toBe(FightStepEnum::WAIT);
});

it('resolves a round and continues when both fighters commit', function (): void {
    $pair = pvpDuelPair(88103, 88104);
    fakeRandom([0.99, 0.0, 0.75, 0.99, 0.99, 0.5, 0.99, 0.0, 0.75, 0.99, 0.99, 0.5]);

    armPvpChoice($pair['fightA']);
    armPvpChoice($pair['fightB']);

    app(PvpRoundService::class)->submitReadyChoice($pair['a']);
    $outcome = app(PvpRoundService::class)->submitReadyChoice($pair['b']);

    expect($outcome->kind)->toBe('continue')
        ->and($pair['duel']->fresh()->status)->toBe(PvpDuelStatusEnum::Active)
        ->and($pair['duel']->fresh()->round)->toBe(2)
        ->and($pair['duel']->fresh()->mutual_skip_streak)->toBe(0)
        ->and($pair['duel']->fresh()->a_choice)->toBeNull()
        ->and($pair['duel']->fresh()->b_choice)->toBeNull()
        ->and($pair['fightA']->fresh()->step)->toBe(FightStepEnum::STANCE)
        ->and($pair['fightB']->fresh()->step)->toBe(FightStepEnum::STANCE)
        ->and($pair['fightA']->fresh()->log)->not->toBe([]);
});

it('awards win and lose arena points when one fighter reaches zero hp', function (): void {
    $pair = pvpDuelPair(88105, 88106);
    fakeRandom([0.99, 0.0, 0.0, 0.0, 0.99, 0.99, 0.5, 0.5, 0.5, 0.5]);

    $fightA = $pair['fightA'];
    $fightB = $pair['fightB'];
    $fightA->player_hp = 200;
    $fightA->save();
    $fightB->player_hp = 1;
    $fightB->save();

    armPvpChoice($fightA->fresh());
    armPvpChoice($fightB->fresh());

    app(PvpRoundService::class)->submitReadyChoice($pair['a']);
    $outcome = app(PvpRoundService::class)->submitReadyChoice($pair['b']);

    expect($outcome->kind)->toBe('finished')
        ->and($outcome->winnerTgId)->toBe($pair['a']->tg_id)
        ->and($pair['duel']->fresh()->status)->toBe(PvpDuelStatusEnum::Done)
        ->and($pair['duel']->fresh()->winner_tg_id)->toBe($pair['a']->tg_id);

    $win = app(FightEndService::class)->finishWin($pair['a']->fresh(), $outcome->fightA);
    $lose = app(FightEndService::class)->finishLose($pair['b']->fresh(), $outcome->fightB);

    expect($pair['a']->fresh()->arena_points)->toBe(60)
        ->and($pair['b']->fresh()->arena_points)->toBe(40)
        ->and(fights()->exists($pair['a']->tg_id))->toBeFalse()
        ->and(fights()->exists($pair['b']->tg_id))->toBeFalse()
        ->and($win->editText)->toContain('Победа на арене')
        ->and($lose->editText)->toContain('Поражение на арене')
        ->and($pair['b']->fresh()->current_hp)->toBe(0);
});

it('increments mutual skip streak and resets it after a real exchange', function (): void {
    $pair = pvpDuelPair(88107, 88108);

    app(PvpRoundService::class)->submitSkip($pair['a']);
    $skipOutcome = app(PvpRoundService::class)->submitSkip($pair['b']);

    expect($skipOutcome->kind)->toBe('continue')
        ->and($pair['duel']->fresh()->mutual_skip_streak)->toBe(1)
        ->and($pair['duel']->fresh()->round)->toBe(2);

    fakeRandom([0.99, 0.0, 0.75, 0.99, 0.99, 0.5, 0.99, 0.0, 0.75, 0.99, 0.99, 0.5]);
    armPvpChoice($pair['fightA']->fresh());
    armPvpChoice($pair['fightB']->fresh());
    app(PvpRoundService::class)->submitReadyChoice($pair['a']->fresh());
    $playOutcome = app(PvpRoundService::class)->submitReadyChoice($pair['b']->fresh());

    expect($playOutcome->kind)->toBe('continue')
        ->and($pair['duel']->fresh()->mutual_skip_streak)->toBe(0)
        ->and($pair['duel']->fresh()->round)->toBe(3);
});

it('aborts the duel and penalizes both after three mutual skips', function (): void {
    $pair = pvpDuelPair(88109, 88110);
    $rounds = app(PvpRoundService::class);

    for ($i = 0; $i < 2; $i++) {
        $rounds->submitSkip($pair['a']->fresh());
        $outcome = $rounds->submitSkip($pair['b']->fresh());
        expect($outcome->kind)->toBe('continue');
    }

    expect($pair['duel']->fresh()->mutual_skip_streak)->toBe(2);

    $rounds->submitSkip($pair['a']->fresh());
    $abort = $rounds->submitSkip($pair['b']->fresh());

    expect($abort->kind)->toBe('aborted')
        ->and($abort->winnerTgId)->toBeNull()
        ->and($pair['duel']->fresh()->status)->toBe(PvpDuelStatusEnum::Done)
        ->and($pair['duel']->fresh()->winner_tg_id)->toBeNull()
        ->and($pair['duel']->fresh()->mutual_skip_streak)->toBe(3)
        ->and(implode("\n", $abort->fightA->log))->toContain('Дисквалификация')
        ->and(implode("\n", $abort->fightB->log))->toContain('Дисквалификация');

    $endA = app(FightEndService::class)->finishPvpAbort($pair['a']->fresh(), $abort->fightA);
    $endB = app(FightEndService::class)->finishPvpAbort($pair['b']->fresh(), $abort->fightB);

    expect($pair['a']->fresh()->arena_points)->toBe(40)
        ->and($pair['b']->fresh()->arena_points)->toBe(40)
        ->and($pair['a']->fresh()->current_hp)->toBeGreaterThan(0)
        ->and($pair['b']->fresh()->current_hp)->toBeGreaterThan(0)
        ->and(fights()->exists($pair['a']->tg_id))->toBeFalse()
        ->and(fights()->exists($pair['b']->tg_id))->toBeFalse()
        ->and($endA->editText)->toContain('Бой сорван')
        ->and($endA->logText)->toContain('Дисквалификация')
        ->and($endB->editText)->toContain('Бой сорван')
        ->and($endB->logText)->toContain('Дисквалификация');
});

it('shows duel round in pvp step prompt instead of turn_seq', function (): void {
    $pair = pvpDuelPair(88114, 88115);

    app(PvpRoundService::class)->submitSkip($pair['a']);
    app(PvpRoundService::class)->submitSkip($pair['b']);

    $fight = $pair['fightA']->fresh();
    expect($fight->turn_seq)->toBeGreaterThan(2)
        ->and($pair['duel']->fresh()->round)->toBe(2);

    $caption = app(FightStatusFormatter::class)->panelCaption(
        $fight,
        $pair['a']->fresh(),
    );

    expect($caption)->toContain('Раунд 2')
        ->and($caption)->not->toContain('Раунд ' . $fight->turn_seq);
});

it('ignores flee callback during a pvp fight', function (): void {
    $pair = pvpDuelPair(88111, 88112);
    $fight = $pair['fightA']->fresh();
    $fight->tg_chat_id = $pair['a']->tg_id;
    $fight->tg_message_id = 70;
    $fight->save();

    $update = fightInlineCallback($pair['a']->tg_id, 'fight:flee');
    app(FightHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    expect(fights()->exists($pair['a']->tg_id))->toBeTrue()
        ->and(fights()->findByTgId($pair['a']->tg_id)->kind)->toBe(FightKindEnum::PVP)
        ->and($pair['duel']->fresh()->status)->toBe(PvpDuelStatusEnum::Active);
});

it('returns missing when skip is submitted without an active pvp fight', function (): void {
    $player = cityDone(88113, 'PvpSkipGhost');

    $outcome = app(PvpRoundService::class)->submitSkip($player);

    expect($outcome->kind)->toBe('missing');
});

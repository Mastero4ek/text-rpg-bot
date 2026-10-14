<?php

declare(strict_types=1);

use App\Actions\City\CityPortalAction;
use App\Actions\LuckyBarrel\LuckyBarrelCashOutAction;
use App\Actions\LuckyBarrel\LuckyBarrelForfeitAction;
use App\Actions\LuckyBarrel\LuckyBarrelOpenAction;
use App\Actions\LuckyBarrel\LuckyBarrelStartAction;
use App\Enums\LuckyBarrel\LuckyBarrelOutcomeEnum;
use App\Enums\LuckyBarrel\LuckyBarrelStatusEnum;
use App\Jobs\IdleSessionResetJob;
use App\Models\City;
use App\Models\LuckyBarrel;
use App\Services\LuckyBarrel\LuckyBarrelService;
use App\Services\Telegram\IdleSessionService;
use App\Support\Random\FakeRandomSource;
use App\Support\Random\RandomSourceContract;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

/**
 * rat_index = floor(roll * 5) + 1 → 0.0 => barrel 1.
 */
function bindLuckyBarrelRatAt(int $barrelIndex): void
{
    if ($barrelIndex < 1 || $barrelIndex > 5) {
        throw new RuntimeException('rat barrel must be 1..5');
    }

    $roll = ($barrelIndex - 1) / 5.0;
    app()->instance(RandomSourceContract::class, new FakeRandomSource([$roll]));
}

function luckyBarrelPlayer(int $tgId, string $nick, int $silver = 100): App\Models\Character\Character
{
    $player = cityDone($tgId, $nick);
    $player->silver = $silver;
    $player->current_hp = 40;
    $player->current_stamina = 30;
    $player->save();

    return $player->fresh();
}

it('P2 starts stake debit and creates IN_PROGRESS session with RNG rat', function (): void {
    bindLuckyBarrelRatAt(1);
    $player = luckyBarrelPlayer(99201, 'LbStart', 100);

    $result = app(LuckyBarrelStartAction::class)->handle($player, 20);

    expect($result->ok)->toBeTrue()
        ->and($result->outcome)->toBe(LuckyBarrelOutcomeEnum::Play)
        ->and($result->character?->silver)->toBe(80)
        ->and($result->session)->toBeInstanceOf(LuckyBarrel::class)
        ->and($result->session?->status)->toBe(LuckyBarrelStatusEnum::InProgress)
        ->and($result->session?->base_stake)->toBe(20)
        ->and($result->session?->rat_index)->toBe(1)
        ->and($result->session?->opened_barrels)->toBe([])
        ->and($result->session?->current_step)->toBe(0);

    expect(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeTrue();
});

it('P3 opens a safe barrel and advances step with payout label', function (): void {
    bindLuckyBarrelRatAt(1);
    $player = luckyBarrelPlayer(99202, 'LbOpenSafe', 100);
    app(LuckyBarrelStartAction::class)->handle($player, 20);

    $result = app(LuckyBarrelOpenAction::class)->handle($player->fresh(), 2);

    expect($result->ok)->toBeTrue()
        ->and($result->outcome)->toBe(LuckyBarrelOutcomeEnum::Play)
        ->and($result->session?->opened_barrels)->toBe([2])
        ->and($result->session?->current_step)->toBe(1)
        ->and(app(LuckyBarrelService::class)->payout(20, 1))->toBe(24);
});

it('P4 cash-out pays floor stake times multiplier and clears session', function (): void {
    bindLuckyBarrelRatAt(1);
    $player = luckyBarrelPlayer(99203, 'LbCash', 100);
    app(LuckyBarrelStartAction::class)->handle($player, 20);
    app(LuckyBarrelOpenAction::class)->handle($player->fresh(), 2);

    $result = app(LuckyBarrelCashOutAction::class)->handle($player->fresh());

    expect($result->ok)->toBeTrue()
        ->and($result->outcome)->toBe(LuckyBarrelOutcomeEnum::Win)
        ->and($result->payout)->toBe(24)
        ->and($result->character?->silver)->toBe(104)
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse();
});

it('P5 auto-pays on fourth safe open without cash click', function (): void {
    bindLuckyBarrelRatAt(1);
    $player = luckyBarrelPlayer(99204, 'LbMax', 100);
    app(LuckyBarrelStartAction::class)->handle($player, 20);

    foreach ([2, 3, 4] as $barrel) {
        $mid = app(LuckyBarrelOpenAction::class)->handle($player->fresh(), $barrel);
        expect($mid->outcome)->toBe(LuckyBarrelOutcomeEnum::Play);
    }

    $result = app(LuckyBarrelOpenAction::class)->handle($player->fresh(), 5);

    expect($result->ok)->toBeTrue()
        ->and($result->outcome)->toBe(LuckyBarrelOutcomeEnum::WinMax)
        ->and($result->payout)->toBe(70)
        ->and($result->character?->silver)->toBe(150)
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse();
});

it('P6 applies floor payouts for stake 50 and 20 at step 1', function (): void {
    $service = app(LuckyBarrelService::class);

    expect($service->payout(50, 1))->toBe(60)
        ->and($service->payout(20, 1))->toBe(24)
        ->and($service->payout(20, 4))->toBe(70);
});

it('P10 lose on rat does not change HP or stamina', function (): void {
    bindLuckyBarrelRatAt(3);
    $player = luckyBarrelPlayer(99205, 'LbHp', 100);
    $hp = $player->current_hp;
    $stamina = $player->current_stamina;

    app(LuckyBarrelStartAction::class)->handle($player, 20);
    $result = app(LuckyBarrelOpenAction::class)->handle($player->fresh(), 3);

    $fresh = $player->fresh();

    expect($result->outcome)->toBe(LuckyBarrelOutcomeEnum::Lose)
        ->and($result->stake)->toBe(20)
        ->and($result->ratIndex)->toBe(3)
        ->and($fresh->silver)->toBe(80)
        ->and($fresh->current_hp)->toBe($hp)
        ->and($fresh->current_stamina)->toBe($stamina)
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse();
});

it('N2 rejects stake when silver is short', function (): void {
    bindLuckyBarrelRatAt(1);
    $player = luckyBarrelPlayer(99206, 'LbPoor', 10);

    $result = app(LuckyBarrelStartAction::class)->handle($player, 20);

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe(__('telegram.npc.barkeeper.error.not_enough_silver'))
        ->and($player->fresh()->silver)->toBe(10)
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse();
});

it('N3 rejects stake outside config stakes', function (): void {
    bindLuckyBarrelRatAt(1);
    $player = luckyBarrelPlayer(99207, 'LbBadStake', 100);

    $result = app(LuckyBarrelStartAction::class)->handle($player, 25);

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe(__('telegram.npc.barkeeper.error.bad_stake'))
        ->and($player->fresh()->silver)->toBe(100)
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse();
});

it('N4 open rat clears session without refund', function (): void {
    bindLuckyBarrelRatAt(1);
    $player = luckyBarrelPlayer(99208, 'LbRat', 100);
    app(LuckyBarrelStartAction::class)->handle($player, 20);

    $result = app(LuckyBarrelOpenAction::class)->handle($player->fresh(), 1);

    expect($result->outcome)->toBe(LuckyBarrelOutcomeEnum::Lose)
        ->and($player->fresh()->silver)->toBe(80)
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse();
});

it('N5 repeated open of same barrel is noop', function (): void {
    bindLuckyBarrelRatAt(1);
    $player = luckyBarrelPlayer(99209, 'LbNoop', 100);
    app(LuckyBarrelStartAction::class)->handle($player, 20);
    app(LuckyBarrelOpenAction::class)->handle($player->fresh(), 2);

    $result = app(LuckyBarrelOpenAction::class)->handle($player->fresh(), 2);
    $session = LuckyBarrel::query()->findOrFail($player->tg_id);

    expect($result->outcome)->toBe(LuckyBarrelOutcomeEnum::Noop)
        ->and($session->opened_barrels)->toBe([2])
        ->and($session->current_step)->toBe(1)
        ->and($player->fresh()->silver)->toBe(80);
});

it('N6 cash at step 0 is noop without payout', function (): void {
    bindLuckyBarrelRatAt(1);
    $player = luckyBarrelPlayer(99210, 'LbCash0', 100);
    app(LuckyBarrelStartAction::class)->handle($player, 20);

    $result = app(LuckyBarrelCashOutAction::class)->handle($player->fresh());

    expect($result->outcome)->toBe(LuckyBarrelOutcomeEnum::Noop)
        ->and($player->fresh()->silver)->toBe(80)
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeTrue();
});

it('N7 open and cash without session fail without awarding silver', function (): void {
    $player = luckyBarrelPlayer(99211, 'LbNoSess', 100);

    $open = app(LuckyBarrelOpenAction::class)->handle($player, 2);
    $cash = app(LuckyBarrelCashOutAction::class)->handle($player);

    expect($open->ok)->toBeFalse()
        ->and($cash->ok)->toBeFalse()
        ->and($player->fresh()->silver)->toBe(100);
});

it('N8 second stake with live session resumes without debit', function (): void {
    bindLuckyBarrelRatAt(1);
    $player = luckyBarrelPlayer(99212, 'LbResume', 100);
    app(LuckyBarrelStartAction::class)->handle($player, 20);

    $result = app(LuckyBarrelStartAction::class)->handle($player->fresh(), 50);

    expect($result->ok)->toBeTrue()
        ->and($result->outcome)->toBe(LuckyBarrelOutcomeEnum::Play)
        ->and($result->session?->base_stake)->toBe(20)
        ->and($player->fresh()->silver)->toBe(80);
});

it('N9 portal forfeits in-progress session without refund', function (): void {
    bindLuckyBarrelRatAt(1);
    $player = luckyBarrelPlayer(99213, 'LbPortal', 200);
    app(LuckyBarrelStartAction::class)->handle($player, 20);

    $target = City::query()->where('key', City::KEY_THORNBREAK)->firstOrFail();
    $target->portal_cost_silver = 0;
    $target->save();

    $res = app(CityPortalAction::class)->handle($player->fresh(), $target->id);

    expect($res->ok)->toBeTrue()
        ->and($res->character?->city_id)->toBe($target->id)
        ->and($res->character?->silver)->toBe(180)
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse();
});

it('N10 idle timeout forfeits in-progress session without refund', function (): void {
    Bus::fake([IdleSessionResetJob::class]);
    bindLuckyBarrelRatAt(1);
    $player = luckyBarrelPlayer(99214, 'LbIdle', 100);
    $player->tg_chat_id = $player->tg_id;
    $player->tg_message_id = 77;
    $player->save();

    app(LuckyBarrelStartAction::class)->handle($player, 20);

    $this->freezeTime();
    app(IdleSessionService::class)->touch($player->fresh());
    $actionAtUnix = $player->fresh()->last_action_at?->getTimestamp();
    expect($actionAtUnix)->toBeInt();

    $this->travel(601)->seconds();
    app()->call([new IdleSessionResetJob($player->tg_id, $actionAtUnix), 'handle']);

    expect(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse()
        ->and($player->fresh()->silver)->toBe(80);
});

it('N12 open index outside 1..5 is noop and keeps session', function (): void {
    bindLuckyBarrelRatAt(1);
    $player = luckyBarrelPlayer(99215, 'LbOob', 100);
    app(LuckyBarrelStartAction::class)->handle($player, 20);

    $result = app(LuckyBarrelOpenAction::class)->handle($player->fresh(), 9);
    $session = LuckyBarrel::query()->findOrFail($player->tg_id);

    expect($result->outcome)->toBe(LuckyBarrelOutcomeEnum::Noop)
        ->and($session->current_step)->toBe(0)
        ->and($session->opened_barrels)->toBe([]);
});

it('forfeit clears session without refund', function (): void {
    bindLuckyBarrelRatAt(1);
    $player = luckyBarrelPlayer(99216, 'LbForfeit', 100);
    app(LuckyBarrelStartAction::class)->handle($player, 20);

    $result = app(LuckyBarrelForfeitAction::class)->handle($player->fresh());

    expect($result->outcome)->toBe(LuckyBarrelOutcomeEnum::Forfeit)
        ->and($player->fresh()->silver)->toBe(80)
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse();
});

it('does not append rat index into opened_barrels on lose', function (): void {
    bindLuckyBarrelRatAt(2);
    $player = luckyBarrelPlayer(99217, 'LbNoAppend', 100);
    app(LuckyBarrelStartAction::class)->handle($player, 20);
    app(LuckyBarrelOpenAction::class)->handle($player->fresh(), 1);

    app(LuckyBarrelOpenAction::class)->handle($player->fresh(), 2);

    expect(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse();
});

<?php

declare(strict_types=1);

use App\Actions\City\CityPortalAction;
use App\Actions\Pvp\PvpChallengeAcceptAction;
use App\Actions\Pvp\PvpChallengeCancelAction;
use App\Actions\Pvp\PvpChallengeCreateAction;
use App\Actions\Pvp\PvpChallengeExpireAction;
use App\Enums\Fight\FightKindEnum;
use App\Enums\Fight\FightReturnEnum;
use App\Enums\Pvp\PvpChallengeStatusEnum;
use App\Enums\Pvp\PvpDuelStatusEnum;
use App\Models\City;
use App\Models\Fight;
use App\Models\Pvp\PvpChallenge;
use App\Models\Pvp\PvpDuel;
use App\Services\Pvp\PvpChallengeService;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    Bus::fake();
});

function pvpBoardPlayer(int $tgId, string $nick, string $cityKey = City::KEY_ANKRAT): App\Models\Character\Character
{
    $player = cityDone($tgId, $nick, $cityKey);
    $player->tg_chat_id = $tgId;
    $player->arena_points = 40;
    $player->save();

    return $player->fresh();
}

describe('create challenge', function (): void {
    it('opens a city challenge with expiry', function (): void {
        $player = pvpBoardPlayer(88001, 'PvpCreateOk');
        $ttl = app(PvpChallengeService::class)->challengeTtlSeconds();

        $this->freezeTime();
        $res = app(PvpChallengeCreateAction::class)->handle($player);

        expect($res->ok)->toBeTrue();

        $challenge = PvpChallenge::query()->where('challenger_tg_id', $player->tg_id)->first();

        expect($challenge)->toBeInstanceOf(PvpChallenge::class)
            ->and($challenge->status)->toBe(PvpChallengeStatusEnum::Open)
            ->and($challenge->city_id)->toBe($player->city_id)
            ->and($challenge->stake_silver)->toBe(0)
            ->and($challenge->expires_at)->not->toBeNull()
            ->and($challenge->expires_at->getTimestamp())->toBe(now()->addSeconds($ttl)->getTimestamp())
            ->and($ttl)->toBe(3600);
    });

    it('rejects create when fights list is disabled', function (): void {
        $player = pvpBoardPlayer(88002, 'PvpCreateNoList');
        $city = City::query()->findOrFail($player->city_id);
        $city->has_fights_list = false;
        $city->save();

        $res = app(PvpChallengeCreateAction::class)->handle($player->fresh());

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('errors.city_unavailable'));
    });

    it('rejects create when hp is below half', function (): void {
        $player = pvpBoardPlayer(88003, 'PvpCreateLowHp');
        $player->current_hp = 1;
        $player->save();

        $res = app(PvpChallengeCreateAction::class)->handle($player->fresh());

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('errors.pvp_hp_low'));
    });

    it('rejects create when already in a fight', function (): void {
        $player = pvpBoardPlayer(88004, 'PvpCreateBusy');
        fights()->createTraining($player, woodenSoldier($player));

        $res = app(PvpChallengeCreateAction::class)->handle($player->fresh());

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('errors.pvp_busy'));
    });

    it('rejects second open challenge for the same player', function (): void {
        $player = pvpBoardPlayer(88005, 'PvpCreateDup');
        app(PvpChallengeCreateAction::class)->handle($player);

        $res = app(PvpChallengeCreateAction::class)->handle($player->fresh());

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('errors.pvp_already_challenging'));
    });
});

describe('cancel challenge', function (): void {
    it('cancels own open challenge', function (): void {
        $player = pvpBoardPlayer(88011, 'PvpCancelOk');
        app(PvpChallengeCreateAction::class)->handle($player);

        $res = app(PvpChallengeCancelAction::class)->handle($player->fresh());

        expect($res->ok)->toBeTrue();

        $challenge = PvpChallenge::query()->where('challenger_tg_id', $player->tg_id)->firstOrFail();

        expect($challenge->status)->toBe(PvpChallengeStatusEnum::Cancelled);
    });

    it('rejects cancel when no open challenge', function (): void {
        $player = pvpBoardPlayer(88012, 'PvpCancelNone');

        $res = app(PvpChallengeCancelAction::class)->handle($player);

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('errors.pvp_no_challenge'));
    });

    it('cancels open challenge when traveling by portal', function (): void {
        $player = pvpBoardPlayer(88013, 'PvpPortalCancel');
        $player->silver = 500;
        $player->save();
        app(PvpChallengeCreateAction::class)->handle($player->fresh());

        $target = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
        $res = app(CityPortalAction::class)->handle($player->fresh(), $target->id);

        expect($res->ok)->toBeTrue();

        $challenge = PvpChallenge::query()->where('challenger_tg_id', $player->tg_id)->firstOrFail();

        expect($challenge->status)->toBe(PvpChallengeStatusEnum::Cancelled)
            ->and($player->fresh()->city_id)->toBe($target->id);
    });
});

describe('accept challenge', function (): void {
    it('starts a duel with two pvp fights', function (): void {
        $challenger = pvpBoardPlayer(88021, 'PvpAcceptA');
        $acceptor = pvpBoardPlayer(88022, 'PvpAcceptB');
        app(PvpChallengeCreateAction::class)->handle($challenger);
        $challenge = PvpChallenge::query()->where('challenger_tg_id', $challenger->tg_id)->firstOrFail();

        $result = app(PvpChallengeAcceptAction::class)->handle($acceptor, $challenge->id);

        expect($result['ok'])->toBeTrue()
            ->and($result['duel'])->toBeInstanceOf(PvpDuel::class)
            ->and($result['duel']->status)->toBe(PvpDuelStatusEnum::Active)
            ->and($result['duel']->a_tg_id)->toBe($challenger->tg_id)
            ->and($result['duel']->b_tg_id)->toBe($acceptor->tg_id)
            ->and($result['duel']->mutual_skip_streak)->toBe(0)
            ->and($result['fightA'])->toBeInstanceOf(Fight::class)
            ->and($result['fightB'])->toBeInstanceOf(Fight::class)
            ->and($result['fightA']->kind)->toBe(FightKindEnum::PVP)
            ->and($result['fightB']->kind)->toBe(FightKindEnum::PVP)
            ->and($result['fightA']->duel_id)->toBe($result['duel']->id)
            ->and($result['fightB']->duel_id)->toBe($result['duel']->id)
            ->and($challenger->fresh()->fight_return)->toBe(FightReturnEnum::Fights)
            ->and($acceptor->fresh()->fight_return)->toBe(FightReturnEnum::Fights);

        $challenge->refresh();

        expect($challenge->status)->toBe(PvpChallengeStatusEnum::Accepted)
            ->and($challenge->accepted_by_tg_id)->toBe($acceptor->tg_id)
            ->and($challenge->duel_id)->toBe($result['duel']->id);
    });

    it('rejects accept of missing challenge', function (): void {
        $acceptor = pvpBoardPlayer(88023, 'PvpAcceptGone');

        $result = app(PvpChallengeAcceptAction::class)->handle($acceptor, 999999);

        expect($result['ok'])->toBeFalse()
            ->and($result['error'])->toBe(__('errors.pvp_challenge_gone'));
    });

    it('rejects accept of expired challenge and marks it expired', function (): void {
        $challenger = pvpBoardPlayer(88024, 'PvpAcceptExpA');
        $acceptor = pvpBoardPlayer(88025, 'PvpAcceptExpB');
        app(PvpChallengeCreateAction::class)->handle($challenger);
        $challenge = PvpChallenge::query()->where('challenger_tg_id', $challenger->tg_id)->firstOrFail();
        $challenge->expires_at = now()->subMinute();
        $challenge->save();

        $result = app(PvpChallengeAcceptAction::class)->handle($acceptor, $challenge->id);

        expect($result['ok'])->toBeFalse()
            ->and($result['error'])->toBe(__('errors.pvp_challenge_gone'))
            ->and($challenge->fresh()->status)->toBe(PvpChallengeStatusEnum::Expired);
    });

    it('rejects accept of own challenge', function (): void {
        $challenger = pvpBoardPlayer(88026, 'PvpAcceptOwn');
        app(PvpChallengeCreateAction::class)->handle($challenger);
        $challenge = PvpChallenge::query()->where('challenger_tg_id', $challenger->tg_id)->firstOrFail();

        $result = app(PvpChallengeAcceptAction::class)->handle($challenger->fresh(), $challenge->id);

        expect($result['ok'])->toBeFalse()
            ->and($result['error'])->toBe(__('errors.pvp_own_challenge'));
    });

    it('rejects accept from another city', function (): void {
        $challenger = pvpBoardPlayer(88027, 'PvpAcceptCityA', City::KEY_ANKRAT);
        $acceptor = pvpBoardPlayer(88028, 'PvpAcceptCityB', City::KEY_ELDWOOD);
        app(PvpChallengeCreateAction::class)->handle($challenger);
        $challenge = PvpChallenge::query()->where('challenger_tg_id', $challenger->tg_id)->firstOrFail();

        $result = app(PvpChallengeAcceptAction::class)->handle($acceptor, $challenge->id);

        expect($result['ok'])->toBeFalse()
            ->and($result['error'])->toBe(__('errors.pvp_wrong_city'));
    });

    it('rejects accept when acceptor hp is low', function (): void {
        $challenger = pvpBoardPlayer(88029, 'PvpAcceptHpA');
        $acceptor = pvpBoardPlayer(88030, 'PvpAcceptHpB');
        $acceptor->current_hp = 1;
        $acceptor->save();
        app(PvpChallengeCreateAction::class)->handle($challenger);
        $challenge = PvpChallenge::query()->where('challenger_tg_id', $challenger->tg_id)->firstOrFail();

        $result = app(PvpChallengeAcceptAction::class)->handle($acceptor->fresh(), $challenge->id);

        expect($result['ok'])->toBeFalse()
            ->and($result['error'])->toBe(__('errors.pvp_hp_low'));
    });

    it('rejects accept when acceptor is already fighting', function (): void {
        $challenger = pvpBoardPlayer(88031, 'PvpAcceptBusyA');
        $acceptor = pvpBoardPlayer(88032, 'PvpAcceptBusyB');
        fights()->createTraining($acceptor, woodenSoldier($acceptor));
        app(PvpChallengeCreateAction::class)->handle($challenger);
        $challenge = PvpChallenge::query()->where('challenger_tg_id', $challenger->tg_id)->firstOrFail();

        $result = app(PvpChallengeAcceptAction::class)->handle($acceptor->fresh(), $challenge->id);

        expect($result['ok'])->toBeFalse()
            ->and($result['error'])->toBe(__('errors.pvp_busy'));
    });

    it('rejects accept when challenger became busy', function (): void {
        $challenger = pvpBoardPlayer(88033, 'PvpAcceptOppA');
        $acceptor = pvpBoardPlayer(88034, 'PvpAcceptOppB');
        app(PvpChallengeCreateAction::class)->handle($challenger);
        $challenge = PvpChallenge::query()->where('challenger_tg_id', $challenger->tg_id)->firstOrFail();
        fights()->createTraining($challenger->fresh(), woodenSoldier($challenger));

        $result = app(PvpChallengeAcceptAction::class)->handle($acceptor, $challenge->id);

        expect($result['ok'])->toBeFalse()
            ->and($result['error'])->toBe(__('errors.pvp_opponent_busy'));
    });

    it('rejects accept outside level bracket', function (): void {
        $challenger = pvpBoardPlayer(88035, 'PvpAcceptLvlA');
        $challenger->level = 1;
        $challenger->save();
        $acceptor = pvpBoardPlayer(88036, 'PvpAcceptLvlB');
        $acceptor->level = 4;
        $acceptor->save();
        app(PvpChallengeCreateAction::class)->handle($challenger->fresh());
        $challenge = PvpChallenge::query()->where('challenger_tg_id', $challenger->tg_id)->firstOrFail();

        $result = app(PvpChallengeAcceptAction::class)->handle($acceptor->fresh(), $challenge->id);

        expect($result['ok'])->toBeFalse()
            ->and($result['error'])->toBe(__('errors.pvp_level_bracket'));
    });
});

describe('expire challenges', function (): void {
    it('expires past open challenges and leaves fresh ones open', function (): void {
        $old = pvpBoardPlayer(88041, 'PvpExpireOld');
        $fresh = pvpBoardPlayer(88042, 'PvpExpireFresh');
        app(PvpChallengeCreateAction::class)->handle($old);
        app(PvpChallengeCreateAction::class)->handle($fresh);

        $oldChallenge = PvpChallenge::query()->where('challenger_tg_id', $old->tg_id)->firstOrFail();
        $oldChallenge->expires_at = now()->subSecond();
        $oldChallenge->save();

        $count = app(PvpChallengeExpireAction::class)->handle();

        expect($count)->toBe(1)
            ->and($oldChallenge->fresh()->status)->toBe(PvpChallengeStatusEnum::Expired)
            ->and(
                PvpChallenge::query()->where('challenger_tg_id', $fresh->tg_id)->firstOrFail()->status
            )->toBe(PvpChallengeStatusEnum::Open);
    });

    it('expires past open challenges via artisan command', function (): void {
        $player = pvpBoardPlayer(88043, 'PvpExpireCmd');
        app(PvpChallengeCreateAction::class)->handle($player);
        $challenge = PvpChallenge::query()->where('challenger_tg_id', $player->tg_id)->firstOrFail();
        $challenge->expires_at = now()->subSecond();
        $challenge->save();

        $this->artisan('pvp:expire-challenges')
            ->expectsOutputToContain('Expired challenges: 1')
            ->assertSuccessful();

        expect($challenge->fresh()->status)->toBe(PvpChallengeStatusEnum::Expired);
    });
});

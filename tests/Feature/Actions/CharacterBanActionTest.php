<?php

declare(strict_types=1);

use App\Actions\Character\CharacterBanAction;
use App\Actions\LuckyBarrel\LuckyBarrelStartAction;
use App\Jobs\Character\CharacterBanReleaseJob;
use App\Models\Fight;
use App\Models\LuckyBarrel;
use App\Support\Random\FakeRandomSource;
use App\Support\Random\RandomSourceContract;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('bans character, clears fight and barrel, pushes jail and schedules release', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
    Queue::fake([CharacterBanReleaseJob::class]);

    $player = cityDone(13101, 'BanOk');
    $player->tg_chat_id = 13101;
    $player->tg_message_id = 50;
    $player->silver = 100;
    $player->save();

    fights()->createTraining($player, woodenSoldier($player));

    app()->instance(RandomSourceContract::class, new FakeRandomSource([0.0]));
    app(LuckyBarrelStartAction::class)->handle($player->fresh(), 20);

    expect(Fight::query()->whereKey($player->tg_id)->exists())->toBeTrue()
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeTrue();

    $until = now()->addDay();
    $banned = app(CharacterBanAction::class)->handle($player->fresh(), $until);

    expect($banned->banned_until)->not->toBeNull()
        ->and($banned->banned_until?->format('Y-m-d H:i'))->toBe($until->format('Y-m-d H:i'))
        ->and(Fight::query()->whereKey($player->tg_id)->exists())->toBeFalse()
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse()
        ->and($banned->silver)->toBe(80);

    Http::assertSent(function (Request $request) use ($until): bool {
        if (! str_contains($request->url(), '/sendPhoto')) {
            return false;
        }

        $stamp = $until->timezone(config('app.timezone'))->format('d.m.Y H:i') . ' (МСК)';

        return str_contains($request->body(), $stamp);
    });

    Queue::assertPushed(CharacterBanReleaseJob::class, function (CharacterBanReleaseJob $job) use ($player, $until): bool {
        return $job->tgId === $player->tg_id
            && $job->bannedUntilUnix === $until->getTimestamp()
            && $job->connection === 'database';
    });
});

it('rejects ban until that is not in the future', function (): void {
    $player = characters()->createDraft(13102);

    expect(fn () => app(CharacterBanAction::class)->handle($player, now()->subMinute()))
        ->toThrow(InvalidArgumentException::class);

    expect($player->fresh()->banned_until)->toBeNull();
});

it('bans without telegram anchor and still schedules release', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
    Queue::fake([CharacterBanReleaseJob::class]);

    $player = characters()->createDraft(13103);
    $until = now()->addHours(3);

    $banned = app(CharacterBanAction::class)->handle($player, $until);

    expect($banned->banned_until)->not->toBeNull()
        ->and($banned->banned_until?->format('Y-m-d H:i'))->toBe($until->format('Y-m-d H:i'));

    Http::assertNothingSent();

    Queue::assertPushed(CharacterBanReleaseJob::class, function (CharacterBanReleaseJob $job) use ($player, $until): bool {
        return $job->tgId === $player->tg_id
            && $job->bannedUntilUnix === $until->getTimestamp()
            && $job->connection === 'database';
    });
});

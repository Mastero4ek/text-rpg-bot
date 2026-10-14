<?php

declare(strict_types=1);

namespace App\Actions\Character;

use App\Actions\Fight\FightClearAction;
use App\Actions\LuckyBarrel\LuckyBarrelForfeitAction;
use App\Jobs\Character\CharacterBanReleaseJob;
use App\Models\Character\Character;
use App\Services\Character\CharacterJailPushService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class CharacterBanAction
{
    public function __construct(
        private readonly CharacterJailPushService $jailPush,
        private readonly FightClearAction $fightClear,
        private readonly LuckyBarrelForfeitAction $luckyBarrelForfeit,
    ) {}

    public function handle(Character $character, CarbonInterface $until): Character
    {
        if (! $until->isFuture()) {
            throw new InvalidArgumentException('Ban until must be in the future.');
        }

        $banned = DB::transaction(function () use ($character, $until): Character {
            $locked = Character::query()
                ->whereKey($character->tg_id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character not found.');
            }

            $this->fightClear->handle($locked->tg_id);
            $this->luckyBarrelForfeit->handle($locked);

            $locked->banned_until = $until;
            $locked->save();

            return $locked->fresh() ?? $locked;
        });

        $this->jailPush->pushJailed($banned);
        $this->scheduleRelease($banned);

        return $banned;
    }

    private function queueSupportsDelay(string $driver): bool
    {
        if ($driver === 'redis') {
            return true;
        }

        if ($driver === 'database') {
            return true;
        }

        if ($driver === 'beanstalkd') {
            return true;
        }

        return $driver === 'sqs';
    }

    private function releaseQueueConnection(): string
    {
        $driver = config('queue.default');

        if (is_string($driver) && $this->queueSupportsDelay($driver)) {
            return $driver;
        }

        return 'database';
    }

    private function scheduleRelease(Character $character): void
    {
        $until = $character->banned_until;

        if (! $until instanceof CarbonInterface) {
            throw new RuntimeException('banned_until missing after ban.');
        }

        $untilUnix = $until->getTimestamp();
        $delaySeconds = max(0, $untilUnix - now()->getTimestamp());

        dispatch(new CharacterBanReleaseJob($character->tg_id, $untilUnix))
            ->onConnection($this->releaseQueueConnection())
            ->delay(now()->addSeconds($delaySeconds));
    }
}

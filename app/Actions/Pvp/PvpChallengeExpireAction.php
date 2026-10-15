<?php

declare(strict_types=1);

namespace App\Actions\Pvp;

use App\Enums\Pvp\PvpChallengeStatusEnum;
use App\Models\Pvp\PvpChallenge;
use Illuminate\Support\Facades\DB;

final class PvpChallengeExpireAction
{
    public function handle(): int
    {
        return DB::transaction(function (): int {
            $ids = PvpChallenge::query()
                ->where('status', PvpChallengeStatusEnum::Open)
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isEmpty()) {
                return 0;
            }

            return PvpChallenge::query()
                ->whereIn('id', $ids->all())
                ->update([
                    'status' => PvpChallengeStatusEnum::Expired->value,
                    'updated_at' => now(),
                ]);
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Character;

use App\Models\Character\Character;
use App\Services\Character\CharacterJailPushService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CharacterUnbanAction
{
    public function __construct(
        private readonly CharacterJailPushService $jailPush,
    ) {}

    public function handle(Character $character): Character
    {
        $hadBan = $character->banned_until !== null;

        $released = DB::transaction(function () use ($character): Character {
            $locked = Character::query()
                ->whereKey($character->tg_id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character not found.');
            }

            if ($locked->banned_until === null) {
                return $locked;
            }

            $locked->banned_until = null;
            $locked->save();

            return $locked->fresh() ?? $locked;
        });

        if ($hadBan) {
            $this->jailPush->pushReleased($released);
        }

        return $released;
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs\Character;

use App\Actions\Character\CharacterUnbanAction;
use App\Models\Character\Character;
use App\Services\Character\CharacterService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CharacterBanReleaseJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $tgId,
        public int $bannedUntilUnix,
    ) {}

    public function handle(
        CharacterService $characters,
        CharacterUnbanAction $unban,
    ): void {
        $character = Character::query()->find($this->tgId);

        if (! $character instanceof Character) {
            return;
        }

        $until = $character->banned_until;

        if (! $until instanceof CarbonInterface) {
            return;
        }

        if ($until->getTimestamp() !== $this->bannedUntilUnix) {
            return;
        }

        if ($characters->hasActiveBan($character)) {
            return;
        }

        $unban->handle($character);
    }
}

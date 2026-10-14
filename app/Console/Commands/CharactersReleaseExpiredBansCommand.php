<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Character\CharacterUnbanAction;
use App\Models\Character\Character;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

final class CharactersReleaseExpiredBansCommand extends Command
{
    protected $signature = 'characters:release-expired-bans';

    protected $description = 'Push jail release for characters whose banned_until has passed.';

    public function handle(CharacterUnbanAction $unban): int
    {
        $released = 0;

        $query = Character::query()
            ->whereNotNull('banned_until')
            ->where('banned_until', '<=', Date::now());

        foreach ($query->cursor() as $character) {
            $unban->handle($character);
            $released++;
        }

        $this->info('Released expired bans: ' . $released);

        return self::SUCCESS;
    }
}

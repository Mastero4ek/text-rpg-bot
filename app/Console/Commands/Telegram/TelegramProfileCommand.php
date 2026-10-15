<?php

declare(strict_types=1);

namespace App\Console\Commands\Telegram;

use App\Services\BotProfileSync;
use Illuminate\Console\Command;

final class TelegramProfileCommand extends Command
{
    protected $signature = 'telegram:profile';

    protected $description = 'Sync Telegram bot name, about, description and profile photo.';

    public function handle(BotProfileSync $profile): int
    {
        $profile->sync();
        $this->info('Bot profile synced (name / about / description / botpic).');
        $this->warn('Description picture: Bot API отсутствует — загрузи logo через BotFather → Edit Description Picture.');

        return self::SUCCESS;
    }
}

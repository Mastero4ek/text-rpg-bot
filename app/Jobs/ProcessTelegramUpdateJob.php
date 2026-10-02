<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Telegram\UpdateProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessTelegramUpdateJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
    ) {}

    public function handle(UpdateProcessor $processor): void
    {
        $processor->handle($this->payload);
    }
}

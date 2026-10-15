<?php

declare(strict_types=1);

namespace App\Services\Appeal;

use App\Enums\Appeal\AppealStatusEnum;
use App\Models\Appeal\Appeal;
use App\Models\Character\Character;
use App\Services\GameConfig;
use App\Support\Telegram\TelegramHtml;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class AppealService
{
    private const string AWAIT_KEY_PREFIX = 'appeal:await:';

    public function __construct(
        private readonly GameConfig $config,
    ) {}

    public function awaitTtlSeconds(): int
    {
        $row = $this->appealConfig();

        if (! array_key_exists('awaitTtlSeconds', $row) || ! is_int($row['awaitTtlSeconds'])) {
            throw new RuntimeException('settings.appeal.awaitTtlSeconds missing.');
        }

        return $row['awaitTtlSeconds'];
    }

    public function cooldownSeconds(): int
    {
        $row = $this->appealConfig();

        if (! array_key_exists('cooldownSeconds', $row) || ! is_int($row['cooldownSeconds'])) {
            throw new RuntimeException('settings.appeal.cooldownSeconds missing.');
        }

        return $row['cooldownSeconds'];
    }

    public function maxBodyLength(): int
    {
        $row = $this->appealConfig();

        if (! array_key_exists('maxBodyLength', $row) || ! is_int($row['maxBodyLength'])) {
            throw new RuntimeException('settings.appeal.maxBodyLength missing.');
        }

        return $row['maxBodyLength'];
    }

    public function maxOpenAppeals(): int
    {
        $row = $this->appealConfig();

        if (! array_key_exists('maxOpenAppeals', $row) || ! is_int($row['maxOpenAppeals'])) {
            throw new RuntimeException('settings.appeal.maxOpenAppeals missing.');
        }

        return $row['maxOpenAppeals'];
    }

    public function beginAwait(Character $character): void
    {
        Cache::put(
            self::AWAIT_KEY_PREFIX . $character->tg_id,
            1,
            now()->addSeconds($this->awaitTtlSeconds()),
        );
    }

    public function clearAwait(Character $character): void
    {
        Cache::forget(self::AWAIT_KEY_PREFIX . $character->tg_id);
    }

    public function isAwaiting(Character $character): bool
    {
        return Cache::has(self::AWAIT_KEY_PREFIX . $character->tg_id);
    }

    /**
     * @return Collection<int, Appeal>
     */
    public function appealsFor(Character $character): Collection
    {
        return Appeal::query()
            ->where('tg_id', $character->tg_id)->latest()
            ->orderByDesc('id')
            ->get();
    }

    public function findOwned(Character $character, int $ticketId): ?Appeal
    {
        return Appeal::query()
            ->whereKey($ticketId)
            ->where('tg_id', $character->tg_id)
            ->first();
    }

    public function openCount(Character $character): int
    {
        return Appeal::query()
            ->where('tg_id', $character->tg_id)
            ->whereIn('status', [
                AppealStatusEnum::New,
                AppealStatusEnum::Read,
            ])
            ->count();
    }

    public function secondsSinceLastCreate(Character $character): ?int
    {
        $latest = Appeal::query()
            ->where('tg_id', $character->tg_id)->latest()
            ->orderByDesc('id')
            ->first();

        if (! $latest instanceof Appeal || $latest->created_at === null) {
            return null;
        }

        return (int) $latest->created_at->diffInSeconds(now());
    }

    public function rowText(Appeal $ticket): string
    {
        $date = '-';

        if ($ticket->created_at !== null) {
            $date = $ticket->created_at->format('d.m.Y');
        }

        return $date . ' · ' . $ticket->status->getLabel();
    }

    public function cardText(Appeal $ticket): string
    {
        $date = '-';

        if ($ticket->created_at !== null) {
            $date = $ticket->created_at->format('d.m.Y');
        }

        return __('telegram.appeal.card', [
            'date' => TelegramHtml::escape($date),
            'status' => TelegramHtml::escape($ticket->status->getLabel()),
            'body' => TelegramHtml::escape($ticket->body),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function appealConfig(): array
    {
        return $this->config->appeal();
    }
}

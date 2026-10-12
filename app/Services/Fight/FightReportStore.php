<?php

declare(strict_types=1);

namespace App\Services\Fight;

use App\Services\GameConfig;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class FightReportStore
{
    private const CAPTION_MAX = 900;

    public function __construct(
        private readonly GameConfig $config,
    ) {}

    public static function cacheKey(int|string $chatId, int $messageId): string
    {
        return 'fight_report:' . $chatId . ':' . $messageId;
    }

    public function clipCaption(string $text): string
    {
        if (mb_strlen($text) <= self::CAPTION_MAX) {
            return $text;
        }

        return mb_substr($text, 0, self::CAPTION_MAX - 1) . '…';
    }

    /**
     * @return array{summary: string, log: string}|null
     */
    public function get(int|string $chatId, int $messageId): ?array
    {
        $payload = Cache::get(self::cacheKey($chatId, $messageId));

        if (! is_array($payload)) {
            return null;
        }

        if (! array_key_exists('summary', $payload) || ! is_string($payload['summary'])) {
            return null;
        }

        if (! array_key_exists('log', $payload) || ! is_string($payload['log'])) {
            return null;
        }

        return [
            'summary' => $payload['summary'],
            'log' => $payload['log'],
        ];
    }

    public function put(int|string $chatId, int $messageId, string $summary, string $log): void
    {
        Cache::put(
            self::cacheKey($chatId, $messageId),
            [
                'summary' => $summary,
                'log' => $log,
            ],
            now()->addSeconds($this->ttlSeconds()),
        );
    }

    private function ttlSeconds(): int
    {
        $telegram = $this->config->telegram();

        if (! array_key_exists('idleSessionSeconds', $telegram) || ! is_int($telegram['idleSessionSeconds'])) {
            throw new RuntimeException('settings.telegram.idleSessionSeconds missing.');
        }

        if ($telegram['idleSessionSeconds'] < 1) {
            throw new RuntimeException('settings.telegram.idleSessionSeconds must be >= 1.');
        }

        return $telegram['idleSessionSeconds'];
    }
}

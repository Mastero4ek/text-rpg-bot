<?php

declare(strict_types=1);

namespace App\Services\Fight;

use App\Services\GameConfig;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class FightReportStore
{
    private const CAPTION_MAX = 1024;

    public function __construct(
        private readonly GameConfig $config,
    ) {}

    public static function cacheKey(int|string $chatId, int $messageId): string
    {
        return 'fight_report:' . $chatId . ':' . $messageId;
    }

    public function clipCaption(string $text): string
    {
        return $this->clipCaptionTo($text, self::CAPTION_MAX);
    }

    public function clipItalicCaption(string $text): string
    {
        $budget = self::CAPTION_MAX - mb_strlen('<i></i>');

        return '<i>' . $this->clipCaptionTo($text, $budget) . '</i>';
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

    private function clipCaptionTo(string $text, int $maxLen): string
    {
        if ($maxLen < 1) {
            throw new RuntimeException('Fight report caption max length must be >= 1.');
        }

        if (mb_strlen($text) <= $maxLen) {
            return $text;
        }

        $marker = "\n\n…\n\n";
        $lines = explode("\n", $text);
        $lineCount = count($lines);

        $bestHead = 0;
        $bestTail = 0;
        $bestKept = -1;
        $bestBalance = -1;

        for ($headCount = 0; $headCount < $lineCount; $headCount++) {
            $maxTail = $lineCount - $headCount - 1;

            for ($tailCount = 0; $tailCount <= $maxTail; $tailCount++) {
                if ($headCount === 0 && $tailCount === 0) {
                    continue;
                }

                $chunk = $this->clipChunk($lines, $headCount, $tailCount, $marker);

                if (mb_strlen($chunk) > $maxLen) {
                    continue;
                }

                $kept = $headCount + $tailCount;
                $balance = $headCount;

                if ($tailCount < $balance) {
                    $balance = $tailCount;
                }

                if ($kept < $bestKept) {
                    continue;
                }

                if ($kept === $bestKept && $balance < $bestBalance) {
                    continue;
                }

                $bestHead = $headCount;
                $bestTail = $tailCount;
                $bestKept = $kept;
                $bestBalance = $balance;
            }
        }

        if ($bestKept < 0) {
            return $this->clipSingleLine($text, $marker, $maxLen);
        }

        return $this->clipChunk($lines, $bestHead, $bestTail, $marker);
    }

    /**
     * @param  list<string>  $lines
     */
    private function clipChunk(array $lines, int $headCount, int $tailCount, string $marker): string
    {
        $lineCount = count($lines);
        $head = array_slice($lines, 0, $headCount);
        $tail = array_slice($lines, $lineCount - $tailCount, $tailCount);

        if ($headCount === 0) {
            return $marker . implode("\n", $tail);
        }

        if ($tailCount === 0) {
            return implode("\n", $head) . $marker;
        }

        return implode("\n", $head) . $marker . implode("\n", $tail);
    }

    private function clipSingleLine(string $text, string $marker, int $maxLen): string
    {
        $budget = $maxLen - mb_strlen($marker);

        if ($budget < 2) {
            return '…';
        }

        $headLen = intdiv($budget, 2);
        $tailLen = $budget - $headLen;

        return mb_substr($text, 0, $headLen) . $marker . mb_substr($text, -1 * $tailLen);
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

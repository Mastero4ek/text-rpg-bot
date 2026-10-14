<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\Models\Character\Character;
use RuntimeException;

final class DemoMode
{
    public static function effectiveLevelMax(int $balanceMax): int
    {
        if (! self::enabled()) {
            return $balanceMax;
        }

        return min($balanceMax, self::maxLevel());
    }

    public static function enabled(): bool
    {
        $enabled = config('bot.demo.enabled');

        if (! is_bool($enabled)) {
            throw new RuntimeException('bot.demo.enabled must be bool.');
        }

        return $enabled;
    }

    public static function isProgressionCapped(Character $character): bool
    {
        if (! self::enabled()) {
            return false;
        }

        return $character->level >= self::maxLevel();
    }

    public static function maxLevel(): int
    {
        $maxLevel = config('bot.demo.max_level');

        if (! is_int($maxLevel)) {
            throw new RuntimeException('bot.demo.max_level must be int.');
        }

        if ($maxLevel < 0) {
            throw new RuntimeException('bot.demo.max_level must be >= 0.');
        }

        return $maxLevel;
    }

    /**
     * @return array{exp: int, silver: int, frozen: bool}
     */
    public static function rewardAmounts(Character $character, int $exp, int $silver): array
    {
        if (self::isProgressionCapped($character)) {
            return [
                'exp' => 0,
                'silver' => 0,
                'frozen' => true,
            ];
        }

        return [
            'exp' => $exp,
            'silver' => $silver,
            'frozen' => false,
        ];
    }

    public static function withBuyerNoPayNotice(Character $character, string $text): string
    {
        if (! self::isProgressionCapped($character)) {
            return $text;
        }

        return $text . "\n\n" . __('profile.demo_buyer_no_pay');
    }

    public static function withRewardFrozenNotice(Character $character, string $text): string
    {
        if (! self::isProgressionCapped($character)) {
            return $text;
        }

        return $text . "\n\n" . __('profile.demo_reward_frozen');
    }
}

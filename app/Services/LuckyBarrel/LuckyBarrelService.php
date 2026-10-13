<?php

declare(strict_types=1);

namespace App\Services\LuckyBarrel;

use App\Enums\LuckyBarrel\LuckyBarrelStatusEnum;
use App\Models\LuckyBarrel;
use App\Services\GameConfig;
use RuntimeException;

final class LuckyBarrelService
{
    public const BARREL_COUNT = 5;

    public const MAX_SAFE_STEPS = 4;

    public function __construct(
        private readonly GameConfig $config,
    ) {}

    public function clear(int $tgId): void
    {
        LuckyBarrel::query()->whereKey($tgId)->delete();
    }

    public function findInProgress(int $tgId): ?LuckyBarrel
    {
        $session = LuckyBarrel::query()->find($tgId);

        if (! $session instanceof LuckyBarrel) {
            return null;
        }

        if ($session->status !== LuckyBarrelStatusEnum::InProgress) {
            return null;
        }

        return $session;
    }

    /**
     * @return list<int>
     */
    public function stakes(): array
    {
        $row = $this->luckyBarrelConfig();

        if (! array_key_exists('stakes', $row) || ! is_array($row['stakes'])) {
            throw new RuntimeException('settings.luckyBarrel.stakes missing.');
        }

        $stakes = [];

        foreach ($row['stakes'] as $stake) {
            if (! is_int($stake)) {
                throw new RuntimeException('settings.luckyBarrel.stakes must be int list.');
            }

            if ($stake < 1) {
                throw new RuntimeException('settings.luckyBarrel.stakes must be >= 1.');
            }

            $stakes[] = $stake;
        }

        if (empty($stakes)) {
            throw new RuntimeException('settings.luckyBarrel.stakes empty.');
        }

        return $stakes;
    }

    public function isAllowedStake(int $stake): bool
    {
        return in_array($stake, $this->stakes(), true);
    }

    public function payout(int $baseStake, int $step): int
    {
        if ($step < 1) {
            return 0;
        }

        return (int) floor($baseStake * $this->multiplierForStep($step));
    }

    public function nextMultiplierLabel(int $currentStep): string
    {
        if ($currentStep >= self::MAX_SAFE_STEPS) {
            return '—';
        }

        return $this->formatMultiplier($this->multiplierForStep($currentStep + 1));
    }

    public function currentWinLabel(int $baseStake, int $step): string
    {
        if ($step < 1) {
            return '0';
        }

        return (string) $this->payout($baseStake, $step);
    }

    public function ratIndexFromFloat(float $roll): int
    {
        if ($roll < 0.0 || $roll >= 1.0) {
            throw new RuntimeException('Random float out of [0, 1).');
        }

        return (int) floor($roll * self::BARREL_COUNT) + 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function luckyBarrelConfig(): array
    {
        $settings = $this->config->settings();

        if (! array_key_exists('luckyBarrel', $settings) || ! is_array($settings['luckyBarrel'])) {
            throw new RuntimeException('settings.luckyBarrel missing.');
        }

        return $settings['luckyBarrel'];
    }

    private function multiplierForStep(int $step): float
    {
        if ($step < 1 || $step > self::MAX_SAFE_STEPS) {
            throw new RuntimeException('Lucky barrel step out of range.');
        }

        $multipliers = $this->multipliers();

        return $multipliers[$step - 1];
    }

    /**
     * @return list<float>
     */
    private function multipliers(): array
    {
        $row = $this->luckyBarrelConfig();

        if (! array_key_exists('multipliers', $row) || ! is_array($row['multipliers'])) {
            throw new RuntimeException('settings.luckyBarrel.multipliers missing.');
        }

        if (count($row['multipliers']) !== self::MAX_SAFE_STEPS) {
            throw new RuntimeException('settings.luckyBarrel.multipliers must have 4 values.');
        }

        $out = [];

        foreach ($row['multipliers'] as $mult) {
            if (! is_int($mult) && ! is_float($mult)) {
                throw new RuntimeException('settings.luckyBarrel.multipliers must be numeric.');
            }

            if ($mult <= 0) {
                throw new RuntimeException('settings.luckyBarrel.multipliers must be > 0.');
            }

            $out[] = (float) $mult;
        }

        return $out;
    }

    private function formatMultiplier(float $mult): string
    {
        $formatted = number_format($mult, 2, '.', '');

        return mb_rtrim(mb_rtrim($formatted, '0'), '.');
    }
}

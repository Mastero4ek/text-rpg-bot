<?php

declare(strict_types=1);

namespace App\Services\City;

use App\Enums\Economy\CurrencyEnum;
use App\Models\Character;

final class CityLedger
{
    public function debit(int $tgId, CurrencyEnum $currency, int $price): bool
    {
        if ($currency === CurrencyEnum::GOLD) {
            $updated = Character::query()
                ->where('tg_id', $tgId)
                ->where('gold', '>=', $price)
                ->decrement('gold', $price);

            return $updated > 0;
        }

        $updated = Character::query()
            ->where('tg_id', $tgId)
            ->where('silver', '>=', $price)
            ->decrement('silver', $price);

        return $updated > 0;
    }

    public function notEnoughMessage(CurrencyEnum $currency): string
    {
        if ($currency === CurrencyEnum::GOLD) {
            return __('errors.not_enough_gold');
        }

        return __('errors.not_enough_silver');
    }
}

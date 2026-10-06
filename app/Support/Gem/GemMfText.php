<?php

declare(strict_types=1);

namespace App\Support\Gem;

final class GemMfText
{
    public static function forDef(GemDef $def): string
    {
        $mf = $def->mf;

        if ($mf->dodge > 0) {
            return __('profile.gear_bonus_mf_dodge', ['value' => $mf->dodge]);
        }

        if ($mf->antiDodge > 0) {
            return __('profile.gear_bonus_mf_anti_dodge', ['value' => $mf->antiDodge]);
        }

        if ($mf->crit > 0) {
            return __('profile.gear_bonus_mf_crit', ['value' => $mf->crit]);
        }

        if ($mf->antiCrit > 0) {
            return __('profile.gear_bonus_mf_anti_crit', ['value' => $mf->antiCrit]);
        }

        return __('profile.gear_bonus_none');
    }
}

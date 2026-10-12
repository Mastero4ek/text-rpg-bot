<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\SkinEnum;

final class CharacterSkinArt
{
    public static function url(?SkinEnum $skin): ?string
    {
        if (! $skin instanceof SkinEnum) {
            return null;
        }

        $relative = 'images/telegram/character/' . $skin->value . '.png';

        if (! is_file(public_path($relative))) {
            return null;
        }

        return asset($relative);
    }
}

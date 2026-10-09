<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Support\HtmlString;
use RuntimeException;

final class CrossedSwordsIcon
{
    public static function html(): HtmlString
    {
        $path = resource_path('images/filament/crossed-swords.svg');
        $contents = file_get_contents($path);

        if ($contents === false || $contents === '') {
            throw new RuntimeException("Unable to read crossed swords icon at [{$path}].");
        }

        return new HtmlString(
            '<span class="fi-fight-crossed-swords" aria-hidden="true">' . $contents . '</span>',
        );
    }
}

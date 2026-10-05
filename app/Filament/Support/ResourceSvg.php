<?php

declare(strict_types=1);

namespace App\Filament\Support;

use RuntimeException;

final class ResourceSvg
{
    public static function markup(string $filename): string
    {
        $path = resource_path('images/' . $filename);
        $contents = file_get_contents($path);

        if ($contents === false || $contents === '') {
            throw new RuntimeException("Unable to read SVG at [{$path}].");
        }

        return $contents;
    }
}

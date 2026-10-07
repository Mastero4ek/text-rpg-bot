<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class LangVariant
{
    public static function pick(string $key): string
    {
        $variants = __($key);

        if (is_string($variants)) {
            if ($variants === '') {
                throw new RuntimeException("Translation [{$key}] is empty.");
            }

            return $variants;
        }

        if ($variants === []) {
            throw new RuntimeException("Translation [{$key}] variants missing.");
        }

        $index = random_int(0, count($variants) - 1);
        $text = $variants[$index];

        if (! is_string($text) || $text === '') {
            throw new RuntimeException("Translation [{$key}] variant invalid.");
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    public static function all(string $key): array
    {
        $variants = __($key);

        if (is_string($variants)) {
            if ($variants === '') {
                throw new RuntimeException("Translation [{$key}] is empty.");
            }

            return [$variants];
        }

        if ($variants === []) {
            throw new RuntimeException("Translation [{$key}] variants missing.");
        }

        $list = [];

        foreach ($variants as $text) {
            if (! is_string($text) || $text === '') {
                throw new RuntimeException("Translation [{$key}] variant invalid.");
            }

            $list[] = $text;
        }

        return $list;
    }
}

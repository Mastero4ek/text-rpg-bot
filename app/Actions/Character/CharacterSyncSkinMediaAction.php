<?php

declare(strict_types=1);

namespace App\Actions\Character;

use App\Enums\SkinEnum;
use App\Models\Character\Character;
use Illuminate\Support\Str;
use RuntimeException;

final class CharacterSyncSkinMediaAction
{
    public function handle(Character $character): void
    {
        if (! $character->skin instanceof SkinEnum) {
            return;
        }

        $images = config('bot.character_skin_images');

        if (! is_array($images) || ! isset($images[$character->skin->value]) || ! is_string($images[$character->skin->value])) {
            throw new RuntimeException('Character skin image path is not configured.');
        }

        $path = $images[$character->skin->value];

        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException('Character skin image file is missing.');
        }

        $character->clearMediaCollection('image');
        $character->addMedia($path)
            ->preservingOriginal()
            ->usingFileName($character->skin->value . '.png')
            ->withProperties(['uuid' => (string) Str::uuid()])
            ->toMediaCollection('image');
    }
}

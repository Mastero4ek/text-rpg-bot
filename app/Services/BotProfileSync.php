<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Telegram\TelegramClient;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

final class BotProfileSync
{
    private const CACHE_PREFIX = 'telegram:bot_profile:';

    public function __construct(
        private readonly TelegramClient $client,
    ) {}

    public function sync(): void
    {
        $errors = [];

        $name = __('telegram.bot.name');
        $this->syncCachedString('name', $name, $errors);

        $about = __('telegram.bot.about');
        $this->syncCachedString('about', $about, $errors);

        $description = __('telegram.bot.description');
        $this->syncCachedString('description', $description, $errors);

        $logo = config('bot.logo_image');

        if (! is_string($logo) || $logo === '') {
            throw new RuntimeException('bot.logo_image is not set.');
        }

        if (! is_file($logo)) {
            throw new RuntimeException('Telegram logo missing: ' . $logo);
        }

        $photoToken = hash_file('xxh128', $logo);

        if ($photoToken === false) {
            throw new RuntimeException('Telegram logo hash failed: ' . $logo);
        }

        if (Cache::get(self::CACHE_PREFIX . 'photo') !== $photoToken) {
            try {
                $this->client->setMyProfilePhoto($logo);
                Cache::forever(self::CACHE_PREFIX . 'photo', $photoToken);
            } catch (Throwable $e) {
                if ($this->isRateLimited($e)) {
                    Cache::forever(self::CACHE_PREFIX . 'photo', $photoToken);
                } else {
                    $errors[] = 'photo: ' . $e->getMessage();
                }
            }
        }

        if (! empty($errors)) {
            throw new RuntimeException(implode('; ', $errors));
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function syncCachedString(string $field, string $value, array &$errors): void
    {
        $cacheKey = self::CACHE_PREFIX . $field;

        if (Cache::get($cacheKey) === $value) {
            return;
        }

        try {
            if ($field === 'name') {
                $this->client->setMyName($value);
            } elseif ($field === 'about') {
                $this->client->setMyShortDescription($value);
            } elseif ($field === 'description') {
                $this->client->setMyDescription($value);
            } else {
                throw new RuntimeException('Unknown bot profile field: ' . $field);
            }

            Cache::forever($cacheKey, $value);
        } catch (Throwable $e) {
            if ($this->isRateLimited($e)) {
                Cache::forever($cacheKey, $value);

                return;
            }

            $errors[] = $field . ': ' . $e->getMessage();
        }
    }

    private function isRateLimited(Throwable $e): bool
    {
        $message = $e->getMessage();

        if (str_contains($message, '"error_code":429')) {
            return true;
        }

        return str_contains($message, 'Too Many Requests');
    }
}

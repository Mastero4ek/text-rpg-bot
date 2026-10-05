<?php

declare(strict_types=1);

namespace App\Services\Game;

use RuntimeException;

final class GameConfig
{
    /** @var array<string, mixed> */
    private array $enemies;

    /** @var array<string, mixed> */
    private array $onboarding;

    /** @var array<string, mixed> */
    private array $settings;

    public function __construct()
    {
        $this->enemies = $this->loadJson('enemies.json');
        $this->onboarding = $this->loadJson('onboarding.json');
        $this->settings = $this->loadJson('settings.json');
    }

    /**
     * @return array<string, mixed>
     */
    public function character(): array
    {
        if (! array_key_exists('character', $this->settings) || ! is_array($this->settings['character'])) {
            throw new RuntimeException('settings.character missing.');
        }

        return $this->settings['character'];
    }

    /**
     * @return array<string, mixed>
     */
    public function combat(): array
    {
        if (! array_key_exists('combat', $this->settings) || ! is_array($this->settings['combat'])) {
            throw new RuntimeException('settings.combat missing.');
        }

        return $this->settings['combat'];
    }

    /**
     * @return array<string, mixed>
     */
    public function enemies(): array
    {
        return $this->enemies;
    }

    /**
     * @return array<string, mixed>
     */
    public function onboarding(): array
    {
        return $this->onboarding;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $filename): array
    {
        $path = resource_path('configs/' . $filename);

        if (! is_file($path)) {
            throw new RuntimeException("Missing game config: {$filename}");
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException("Cannot read game config: {$filename}");
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid JSON in game config: {$filename}");
        }

        return $decoded;
    }
}

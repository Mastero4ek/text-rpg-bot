<?php

declare(strict_types=1);

namespace App\Services\Game;

use RuntimeException;

final class GameConfig
{
    /** @var array<string, mixed> */
    private array $character;

    /** @var array<string, mixed> */
    private array $combat;

    /** @var array<string, mixed> */
    private array $enemies;

    /** @var array<string, mixed> */
    private array $onboarding;

    /** @var array<string, mixed> */
    private array $shop;

    public function __construct()
    {
        $this->character = $this->loadJson('character.json');
        $this->combat = $this->loadJson('combat.json');
        $this->enemies = $this->loadJson('enemies.json');
        $this->onboarding = $this->loadJson('onboarding.json');
        $this->shop = $this->loadJson('shop.json');
    }

    /**
     * @return array<string, mixed>
     */
    public function character(): array
    {
        return $this->character;
    }

    /**
     * @return array<string, mixed>
     */
    public function combat(): array
    {
        return $this->combat;
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
    public function shop(): array
    {
        return $this->shop;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $filename): array
    {
        $path = resource_path('game/' . $filename);

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

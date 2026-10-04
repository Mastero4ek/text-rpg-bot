<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Enums\Economy\CurrencyEnum;
use App\Support\Game\GemDef;
use App\Support\Game\Mf;
use RuntimeException;

final class GemCatalog
{
    /** @var array<string, GemDef>|null */
    private ?array $byId = null;

    public function breakChanceOnLose(): int
    {
        $raw = $this->config()['breakChanceOnLose'];

        if (! is_int($raw) || $raw < 0 || $raw > 100) {
            throw new RuntimeException('gems.breakChanceOnLose must be 0..100.');
        }

        return $raw;
    }

    public function find(string $gemId): GemDef
    {
        $map = $this->allById();

        if (! array_key_exists($gemId, $map)) {
            throw new RuntimeException("Unknown gem {$gemId}");
        }

        return $map[$gemId];
    }

    public function has(string $gemId): bool
    {
        return array_key_exists($gemId, $this->allById());
    }

    public function insuranceGold(): int
    {
        $raw = $this->config()['insuranceGold'];

        if (! is_int($raw) || $raw < 1) {
            throw new RuntimeException('gems.insuranceGold must be >= 1.');
        }

        return $raw;
    }

    public function premiumBreakChanceReduce(): int
    {
        $raw = $this->config()['premiumBreakChanceReduce'];

        if (! is_int($raw) || $raw < 0 || $raw > 100) {
            throw new RuntimeException('gems.premiumBreakChanceReduce must be 0..100.');
        }

        return $raw;
    }

    /**
     * @return list<GemDef>
     */
    public function shopGems(): array
    {
        $items = [];

        foreach ($this->allById() as $gem) {
            if (! $gem->inShop) {
                continue;
            }

            $items[] = $gem;
        }

        usort($items, function (GemDef $a, GemDef $b): int {
            return $a->id <=> $b->id;
        });

        return $items;
    }

    /**
     * @return array<string, string>
     */
    /**
     * @return list<string>
     */
    public function types(): array
    {
        $types = [];

        foreach ($this->allById() as $gem) {
            if (in_array($gem->type, $types, true)) {
                continue;
            }

            $types[] = $gem->type;
        }

        sort($types);

        return $types;
    }

    public function unsocketSilver(): int
    {
        $raw = $this->config()['unsocketSilver'];

        if (! is_int($raw) || $raw < 0) {
            throw new RuntimeException('gems.unsocketSilver must be >= 0.');
        }

        return $raw;
    }

    /**
     * @return array<string, GemDef>
     */
    private function allById(): array
    {
        if ($this->byId !== null) {
            return $this->byId;
        }

        $rawGems = $this->config()['gems'];

        if (! is_array($rawGems)) {
            throw new RuntimeException('gems.gems must be an array.');
        }

        $map = [];

        foreach ($rawGems as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('gems.gems row must be an object.');
            }

            $gem = $this->gemFromRow($row);
            $map[$gem->id] = $gem;
        }

        $this->byId = $map;

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $path = resource_path('game/gems.json');

        if (! is_file($path)) {
            throw new RuntimeException('Missing game config: gems.json');
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException('Cannot read game config: gems.json');
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid JSON in game config: gems.json');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function gemFromRow(array $row): GemDef
    {
        if (! array_key_exists('id', $row) || ! is_string($row['id']) || $row['id'] === '') {
            throw new RuntimeException('gem id missing.');
        }

        if (! array_key_exists('type', $row) || ! is_string($row['type']) || $row['type'] === '') {
            throw new RuntimeException("gem {$row['id']} type missing.");
        }

        if (! array_key_exists('name', $row) || ! is_string($row['name']) || $row['name'] === '') {
            throw new RuntimeException("gem {$row['id']} name missing.");
        }

        if (! array_key_exists('price', $row) || ! is_int($row['price']) || $row['price'] < 0) {
            throw new RuntimeException("gem {$row['id']} price invalid.");
        }

        if (! array_key_exists('currency', $row) || ! is_string($row['currency'])) {
            throw new RuntimeException("gem {$row['id']} currency missing.");
        }

        $currency = CurrencyEnum::from($row['currency']);

        $inShop = false;

        if (array_key_exists('in_shop', $row) && is_bool($row['in_shop'])) {
            $inShop = $row['in_shop'];
        }

        if (! array_key_exists('mf', $row) || ! is_array($row['mf'])) {
            throw new RuntimeException("gem {$row['id']} mf missing.");
        }

        return new GemDef(
            $row['id'],
            $row['type'],
            $row['name'],
            $row['price'],
            $currency,
            $inShop,
            Mf::fromArray($row['mf']),
        );
    }
}

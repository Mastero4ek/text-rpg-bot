<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->beforeEach(function (): void {
    $this->seed(Database\Seeders\BackpackCatalogSeeder::class);
    $this->seed(Database\Seeders\BagCatalogSeeder::class);
})->in('Feature');

require_once __DIR__ . '/Helpers.php';

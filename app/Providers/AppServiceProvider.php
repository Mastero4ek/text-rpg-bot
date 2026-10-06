<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Bag\BagCatalog as BagCatalogService;
use App\Services\GameConfig;
use App\Services\Shop\ShopCatalog;
use App\Support\Random\PhpRandomSource;
use App\Support\Random\RandomSourceContract;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GameConfig::class);
        $this->app->singleton(ShopCatalog::class);
        $this->app->singleton(BagCatalogService::class);
        $this->app->singleton(RandomSourceContract::class, PhpRandomSource::class);
    }

    public function boot(): void
    {
        $webhookUrl = config('bot.webhook_url');

        if (! is_string($webhookUrl) || $webhookUrl === '') {
            DevCommands::artisan('telegram:poll', 'telegram');
        }
    }
}

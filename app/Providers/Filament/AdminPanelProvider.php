<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Resources\Characters\RelationManagers\BackpackRelationManager;
use App\Filament\Resources\Characters\RelationManagers\BagRelationManager;
use App\Filament\Resources\CityCatalog\CityCatalogResource;
use App\Filament\Resources\CityCatalog\RelationManagers\CharactersRelationManager;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Tables\View\TablesRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->databaseNotifications()
            ->globalSearch(false)
            ->colors([
                'primary' => Color::Teal,
            ])
            ->maxContentWidth(Width::Full)
            ->assets([
                Css::make('admin', resource_path('styles/admin.css')),
            ])
            ->homeUrl(fn (): string => CityCatalogResource::getUrl('index'))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->renderHook(
                TablesRenderHook::TOOLBAR_START,
                fn (): Htmlable => BackpackRelationManager::tableCapacityToolbar(),
                scopes: BackpackRelationManager::class,
            )
            ->renderHook(
                TablesRenderHook::TOOLBAR_START,
                fn (): Htmlable => BagRelationManager::tableCapacityToolbar(),
                scopes: BagRelationManager::class,
            )
            ->renderHook(
                TablesRenderHook::TOOLBAR_START,
                fn (): Htmlable => CharactersRelationManager::tableCapacityToolbar(),
                scopes: CharactersRelationManager::class,
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}

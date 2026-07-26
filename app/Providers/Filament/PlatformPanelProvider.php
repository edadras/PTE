<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Platform\Pages\PlatformDashboard;
use App\Filament\Support\Middleware\EnsureSuperAdmin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * `/platform` — the company's own panel.
 *
 * Deliberately *not* tenant-aware: nothing here runs inside TenantContext, and
 * every cross-academy read goes through `withoutTenantScope()`, which is gated
 * on `platform.view_all`.
 *
 * @see docs/02-roles-and-rbac.md §2
 */
final class PlatformPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('platform')
            ->path('platform')
            ->login()
            ->brandName(fn (): string => (string) config('pte.platform.name', 'PTE Platform'))
            ->colors([
                'primary' => Color::Indigo,
                'danger' => Color::Rose,
                'warning' => Color::Amber,
                'success' => Color::Emerald,
            ])
            ->darkMode()
            ->discoverResources(
                in: app_path('Filament/Platform/Resources'),
                for: 'App\\Filament\\Platform\\Resources',
            )
            ->discoverPages(
                in: app_path('Filament/Platform/Pages'),
                for: 'App\\Filament\\Platform\\Pages',
            )
            ->discoverWidgets(
                in: app_path('Filament/Platform/Widgets'),
                for: 'App\\Filament\\Platform\\Widgets',
            )
            ->pages([
                PlatformDashboard::class,
            ])
            ->navigationGroups([
                NavigationGroup::make()->label(fn (): string => __('panel.nav.tenants')),
                NavigationGroup::make()->label(fn (): string => __('panel.nav.catalogue')),
                NavigationGroup::make()->label(fn (): string => __('panel.nav.operations')),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureSuperAdmin::class,
            ], isPersistent: true);
    }
}

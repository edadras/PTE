<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Domain\Tenancy\Middleware\ResolveTenantFromDomain;
use App\Filament\Academy\Pages\AcademyDashboard;
use App\Filament\Support\BrandContext;
use App\Filament\Support\Http\StartImpersonationController;
use App\Filament\Support\Http\StopImpersonationController;
use App\Filament\Support\Middleware\EnforceImpersonationWindow;
use App\Filament\Support\Middleware\EnsureAcademyMember;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * `/panel` — one panel, every academy, told apart by the Host header.
 *
 * Tenancy is *not* Filament's multi-tenancy feature: the tenant comes from
 * ResolveTenantFromDomain and is enforced by the BelongsToAcademy global scope,
 * so no resource ever writes a `where academy_id` of its own.
 *
 * @see docs/01-multi-tenancy.md §4.2 · docs/03-white-label.md §4.1
 */
final class AcademyPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('panel')
            ->path('panel')
            ->login()
            ->passwordReset()
            ->profile()
            // Evaluated per request; the brand may not be resolvable yet on an
            // unknown host, hence the fallbacks inside BrandContext.
            ->brandName(fn (): string => BrandContext::displayName())
            ->brandLogo(fn (): ?string => BrandContext::logoUrl())
            ->darkModeBrandLogo(fn (): ?string => BrandContext::logoUrl(dark: true))
            ->favicon(fn (): ?string => BrandContext::iconUrl())
            ->colors(fn (): array => self::brandColors())
            ->darkMode()
            ->discoverResources(
                in: app_path('Filament/Academy/Resources'),
                for: 'App\\Filament\\Academy\\Resources',
            )
            ->discoverPages(
                in: app_path('Filament/Academy/Pages'),
                for: 'App\\Filament\\Academy\\Pages',
            )
            ->discoverWidgets(
                in: app_path('Filament/Academy/Widgets'),
                for: 'App\\Filament\\Academy\\Widgets',
            )
            ->pages([
                AcademyDashboard::class,
            ])
            ->navigationGroups([
                NavigationGroup::make()->label(fn (): string => __('panel.nav.people')),
                NavigationGroup::make()->label(fn (): string => __('panel.nav.content')),
                NavigationGroup::make()->label(fn (): string => __('panel.nav.assessment')),
                NavigationGroup::make()->label(fn (): string => __('panel.nav.telegram')),
                NavigationGroup::make()->label(fn (): string => __('panel.nav.ai')),
                NavigationGroup::make()->label(fn (): string => __('panel.nav.settings')),
            ])
            ->routes(function (): void {
                Route::get('impersonation/start', StartImpersonationController::class)
                    ->middleware('signed')
                    ->name('impersonation.start');
            })
            ->authenticatedRoutes(function (): void {
                Route::get('impersonation/stop', StopImpersonationController::class)
                    ->name('impersonation.stop');
            })
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.components.brand-head')->render(),
            )
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn (): string => view('filament.components.impersonation-banner')->render(),
            )
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
                // Must precede the auth middleware: it installs the permission
                // team id that every Gate check below depends on.
                ResolveTenantFromDomain::class,
                EnforceImpersonationWindow::class,
            ], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
                EnsureAcademyMember::class,
            ], isPersistent: true);
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    private static function brandColors(): array
    {
        $palette = BrandContext::palette();

        return [
            'primary' => Color::hex($palette['primary']),
            'secondary' => Color::hex($palette['secondary']),
            'success' => Color::hex($palette['success']),
            'danger' => Color::hex($palette['danger']),
            'info' => Color::hex($palette['accent']),
        ];
    }
}

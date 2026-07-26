<?php

declare(strict_types=1);

namespace App\Filament\Academy\Pages;

use App\Domain\Commerce\Data\QuotaResult;
use App\Domain\Commerce\Models\Invoice;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\Plan;
use App\Domain\Commerce\Models\Subscription;
use App\Domain\Commerce\Services\QuotaGuard;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

/**
 * Plan, quota usage, invoices and payments (docs/09).
 *
 * The usage bars come straight from QuotaGuard::snapshot(), so the panel and
 * the enforcement path can never disagree about how much is left.
 */
final class BillingPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.academy.pages.billing';

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('panel.billing.title');
    }

    public function getTitle(): string
    {
        return __('panel.billing.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('billing.view') === true;
    }

    public function getSubscription(): ?Subscription
    {
        return Subscription::query()->live()->orderByDesc('id')->first();
    }

    public function getPlan(): ?Plan
    {
        return app(QuotaGuard::class)->plan();
    }

    /**
     * @return array<string, QuotaResult>
     */
    public function getQuotas(): array
    {
        return app(QuotaGuard::class)->snapshot();
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function getInvoices(): Collection
    {
        return Invoice::query()->orderByDesc('id')->limit(24)->get();
    }

    /**
     * @return Collection<int, Payment>
     */
    public function getPayments(): Collection
    {
        return Payment::query()->orderByDesc('id')->limit(24)->get();
    }
}

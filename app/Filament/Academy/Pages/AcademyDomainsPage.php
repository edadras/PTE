<?php

declare(strict_types=1);

namespace App\Filament\Academy\Pages;

use App\Domain\Commerce\Services\QuotaGuard;
use App\Domain\Tenancy\Enums\DomainType;
use App\Domain\Tenancy\Enums\SslStatus;
use App\Domain\Tenancy\Models\AcademyDomain;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Support\DomainVerification;
use Closure;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Custom domains and their DNS TXT verification (docs/01 §6, docs/03 §7).
 *
 * AcademyDomain is deliberately not tenant-scoped (it is how the tenant is
 * found), so everything here reads through the academy's own relation.
 */
final class AcademyDomainsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?int $navigationSort = 25;

    protected static string $view = 'filament.academy.pages.academy-domains';

    protected static ?string $slug = 'domains';

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('panel.domains.title');
    }

    public function getTitle(): string
    {
        return __('panel.domains.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('academy.domain.manage') === true;
    }

    /**
     * @return Collection<int, AcademyDomain>
     */
    public function getDomains(): Collection
    {
        return TenantContext::require()
            ->domains()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();
    }

    public function planAllowsCustomDomain(): bool
    {
        $plan = app(QuotaGuard::class)->plan();

        // No subscription (e.g. a platform-managed academy) is not a reason to
        // block the page; a plan that explicitly lacks the feature is.
        return $plan === null || $plan->hasFeature('custom_domain');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('addDomain')
                ->label(__('panel.domains.action.add'))
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => auth()->user()?->can('academy.domain.manage') === true)
                ->disabled(fn (): bool => ! $this->planAllowsCustomDomain())
                ->form([
                    Forms\Components\TextInput::make('hostname')
                        ->label(__('panel.domains.field.hostname'))
                        ->required()
                        ->maxLength(191)
                        ->placeholder('panel.my-academy.com')
                        ->rules([
                            static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                                $hostname = AcademyDomain::normalizeHostname((string) $value);

                                if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $hostname) !== 1) {
                                    $fail(__('panel.domains.error.invalid'));

                                    return;
                                }

                                $root = (string) config('pte.platform.root_domain');

                                if ($root !== '' && ($hostname === $root || str_ends_with($hostname, '.'.$root))) {
                                    $fail(__('panel.domains.error.reserved'));

                                    return;
                                }

                                if (AcademyDomain::query()->forHostname($hostname)->exists()) {
                                    $fail(__('panel.domains.error.taken'));
                                }
                            },
                        ]),
                ])
                ->action(function (array $data): void {
                    Gate::authorize('academy.domain.manage');

                    if (! $this->planAllowsCustomDomain()) {
                        Notification::make()->danger()->title(__('panel.domains.notify.plan_locked'))->send();

                        return;
                    }

                    AcademyDomain::query()->create([
                        'academy_id' => TenantContext::id(),
                        'hostname' => AcademyDomain::normalizeHostname((string) $data['hostname']),
                        'type' => DomainType::Custom,
                        'is_primary' => false,
                        'ssl_status' => SslStatus::Pending,
                        'verification_token' => 'pte-verify-'.Str::random(40),
                    ]);

                    Notification::make()
                        ->success()
                        ->title(__('panel.domains.notify.added'))
                        ->body(__('panel.domains.notify.added_body'))
                        ->send();
                }),
        ];
    }

    public function verify(int $domainId): void
    {
        Gate::authorize('academy.domain.manage');

        $domain = $this->find($domainId);

        if ($domain === null) {
            return;
        }

        if (app(DomainVerification::class)->verify($domain)) {
            Notification::make()->success()->title(__('panel.domains.notify.verified'))->send();

            return;
        }

        Notification::make()
            ->danger()
            ->title(__('panel.domains.notify.verify_failed'))
            ->body(__('panel.domains.notify.verify_failed_body', [
                'record' => $domain->dnsVerificationRecord(),
            ]))
            ->send();
    }

    public function remove(int $domainId): void
    {
        Gate::authorize('academy.domain.manage');

        $domain = $this->find($domainId);

        if ($domain === null) {
            return;
        }

        // Deleting the primary (or only) domain would lock the academy out of
        // its own panel.
        if ($domain->is_primary || $domain->type === DomainType::Subdomain) {
            Notification::make()->danger()->title(__('panel.domains.notify.cannot_remove'))->send();

            return;
        }

        $domain->delete();

        Notification::make()->success()->title(__('panel.domains.notify.removed'))->send();
    }

    private function find(int $domainId): ?AcademyDomain
    {
        /** @var AcademyDomain|null $domain */
        $domain = TenantContext::require()->domains()->whereKey($domainId)->first();

        return $domain;
    }
}

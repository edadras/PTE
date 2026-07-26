<?php

declare(strict_types=1);

namespace App\Filament\Academy\Pages;

use App\Domain\Integration\Enums\WebhookDeliveryStatus;
use App\Domain\Integration\Enums\WebhookEvent;
use App\Domain\Integration\Jobs\DispatchWebhook;
use App\Domain\Integration\Models\Webhook;
use App\Domain\Integration\Models\WebhookDelivery;
use App\Domain\Telegram\Support\SafeUrl;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Outbound webhook endpoints and their delivery log (docs/08 §5).
 *
 * The HMAC secret is generated server-side and revealed exactly once, on the
 * render after creation; afterwards the encrypted model attribute never leaves
 * the server again. Redelivery goes through the same DispatchWebhook job the
 * emitter uses — same signature, same SSRF guard, same retry ladder.
 */
final class WebhooksPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?int $navigationSort = 45;

    protected static string $view = 'filament.academy.pages.webhooks';

    protected static ?string $slug = 'webhooks';

    public ?string $revealedSecret = null;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('panel.webhooks.title');
    }

    public function getTitle(): string
    {
        return __('panel.webhooks.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('webhooks.manage') === true;
    }

    /**
     * @return Collection<int, Webhook>
     */
    public function getWebhooks(): Collection
    {
        return Webhook::query()->orderByDesc('id')->get();
    }

    /**
     * @return Collection<int, WebhookDelivery>
     */
    public function getRecentDeliveries(): Collection
    {
        return WebhookDelivery::query()
            ->with('webhook')
            ->orderByDesc('id')
            ->limit(25)
            ->get();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('panel.webhooks.action.create'))
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => auth()->user()?->can('webhooks.manage') === true)
                ->form(self::webhookForm())
                ->action(function (array $data): void {
                    Gate::authorize('webhooks.manage');

                    $secret = 'whsec_'.Str::random(48);

                    Webhook::query()->create([
                        'name' => (string) $data['name'],
                        'url' => (string) $data['url'],
                        'secret' => $secret,
                        'events' => array_values(array_map(strval(...), (array) ($data['events'] ?? []))),
                        'is_active' => true,
                        'created_by' => auth()->id(),
                    ]);

                    // Shown once, on this render only.
                    $this->revealedSecret = $secret;

                    Notification::make()
                        ->success()
                        ->title(__('panel.webhooks.notify.created'))
                        ->body(__('panel.webhooks.notify.created_body'))
                        ->persistent()
                        ->send();
                }),
        ];
    }

    public function editAction(): Action
    {
        return Action::make('edit')
            ->label(__('panel.webhooks.action.edit'))
            ->size('sm')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('webhooks.manage') === true)
            ->form(self::webhookForm())
            ->fillForm(function (array $arguments): array {
                $webhook = $this->findWebhook((int) ($arguments['webhook'] ?? 0));

                // The secret is intentionally absent: it is never hydrated back.
                return $webhook === null ? [] : [
                    'name' => $webhook->name,
                    'url' => $webhook->url,
                    'events' => $webhook->events,
                ];
            })
            ->action(function (array $arguments, array $data): void {
                Gate::authorize('webhooks.manage');

                $webhook = $this->findWebhook((int) ($arguments['webhook'] ?? 0));

                if ($webhook === null) {
                    return;
                }

                $webhook->forceFill([
                    'name' => (string) $data['name'],
                    'url' => (string) $data['url'],
                    'events' => array_values(array_map(strval(...), (array) ($data['events'] ?? []))),
                ])->save();

                Notification::make()->success()->title(__('panel.webhooks.notify.updated'))->send();
            });
    }

    public function toggle(int $webhookId): void
    {
        Gate::authorize('webhooks.manage');

        $webhook = $this->findWebhook($webhookId);

        if ($webhook === null) {
            return;
        }

        $webhook->forceFill([
            'is_active' => ! $webhook->is_active,
            // Re-enabling clears an auto-disable so deliveries can resume.
            'disabled_at' => null,
            'disabled_reason' => null,
            'consecutive_failures' => 0,
        ])->save();

        Notification::make()->success()->title(__('panel.webhooks.notify.updated'))->send();
    }

    public function remove(int $webhookId): void
    {
        Gate::authorize('webhooks.manage');

        $this->findWebhook($webhookId)?->delete();

        Notification::make()->success()->title(__('panel.webhooks.notify.deleted'))->send();
    }

    /**
     * Queue a fresh attempt-set for a past delivery: a new row (so `attempt`
     * and dedupe ids stay honest) with the same event and payload.
     */
    public function redeliver(int $deliveryId): void
    {
        Gate::authorize('webhooks.manage');

        $delivery = WebhookDelivery::query()->with('webhook')->find($deliveryId);

        if (! $delivery instanceof WebhookDelivery) {
            return;
        }

        $webhook = $delivery->webhook;

        if (! $webhook instanceof Webhook || ! $webhook->isDeliverable()) {
            Notification::make()->danger()->title(__('panel.webhooks.notify.redeliver_disabled'))->send();

            return;
        }

        /** @var WebhookDelivery $fresh */
        $fresh = WebhookDelivery::query()->create([
            'webhook_id' => $webhook->getKey(),
            'event' => $delivery->event,
            'payload' => $delivery->payload,
            'status' => WebhookDeliveryStatus::Pending,
        ]);

        DispatchWebhook::dispatch(TenantContext::id(), (int) $webhook->getKey(), (int) $fresh->getKey())
            ->onQueue((string) config('pte.queues.notifications', 'notifications'));

        Notification::make()->success()->title(__('panel.webhooks.notify.redelivered'))->send();
    }

    public function dismissRevealedSecret(): void
    {
        $this->revealedSecret = null;
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private static function webhookForm(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->label(__('panel.webhooks.field.name'))
                ->required()
                ->maxLength(100),
            Forms\Components\TextInput::make('url')
                ->label(__('panel.webhooks.field.url'))
                ->url()
                ->required()
                ->maxLength(2048)
                ->helperText(__('panel.webhooks.help.url'))
                ->rules([
                    static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                        if (is_string($value) && $value !== '' && ! SafeUrl::isSafe($value)) {
                            $fail(__('api.errors.webhook_url_rejected'));
                        }
                    },
                ]),
            Forms\Components\CheckboxList::make('events')
                ->label(__('panel.webhooks.field.events'))
                ->options(fn (): array => collect(WebhookEvent::cases())
                    ->mapWithKeys(fn (WebhookEvent $event): array => [$event->value => $event->label()])
                    ->all())
                ->required()
                ->columns(2),
        ];
    }

    private function findWebhook(int $webhookId): ?Webhook
    {
        /** @var Webhook|null $webhook */
        $webhook = Webhook::query()->find($webhookId);

        return $webhook;
    }
}

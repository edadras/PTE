<?php

declare(strict_types=1);

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Enums\WebhookDeliveryStatus;
use App\Domain\Integration\Enums\WebhookEvent;
use App\Domain\Integration\Jobs\DispatchWebhook;
use App\Domain\Integration\Models\Webhook;
use App\Domain\Integration\Models\WebhookDelivery;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * The single place a domain event becomes an outbound HTTP call.
 *
 * Emitting is deliberately fire-and-forget and never throws: an academy's CRM
 * being down must not roll back a student's exam submission.
 *
 * @see docs/08-api-and-integrations.md §5
 */
final class WebhookEmitter
{
    /**
     * @param  array<string, mixed>  $payload
     * @return int number of endpoints the event was queued for
     */
    public function emit(WebhookEvent|string $event, array $payload, ?int $academyId = null): int
    {
        $resolved = WebhookEvent::tryFromMixed($event);

        if (! $resolved instanceof WebhookEvent) {
            return 0;
        }

        $academyId ??= TenantContext::idOrNull();

        if ($academyId === null) {
            return 0;
        }

        $queued = 0;

        foreach ($this->targets($resolved, $academyId) as $webhook) {
            /** @var WebhookDelivery $delivery */
            $delivery = WebhookDelivery::query()->create([
                'academy_id' => $academyId,
                'webhook_id' => $webhook->getKey(),
                'event' => $resolved->value,
                'payload' => $payload,
                'status' => WebhookDeliveryStatus::Pending,
            ]);

            DispatchWebhook::dispatch($academyId, (int) $webhook->getKey(), (int) $delivery->getKey())
                ->onQueue((string) config('pte.queues.notifications', 'notifications'));

            $queued++;
        }

        return $queued;
    }

    /**
     * @return Collection<int, Webhook>
     */
    private function targets(WebhookEvent $event, int $academyId): Collection
    {
        return Webhook::query()
            ->forAcademy($academyId)
            ->deliverable()
            ->get()
            ->filter(static fn (Webhook $webhook): bool => $webhook->subscribesTo($event))
            ->values();
    }
}

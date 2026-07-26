<?php

declare(strict_types=1);

namespace App\Domain\Integration\Jobs;

use App\Domain\Integration\Enums\WebhookDeliveryStatus;
use App\Domain\Integration\Models\Webhook;
use App\Domain\Integration\Models\WebhookDelivery;
use App\Domain\Integration\Support\WebhookSignature;
use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Domain\Telegram\Support\SafeUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Delivers one webhook payload, with the retry ladder of docs/08 §5.
 *
 * Two rules shape this job:
 *
 *  - the SSRF check runs *here*, not only when the URL was saved. DNS can be
 *    re-pointed at 10.0.0.1 the minute after the academy saved a public host,
 *    so the address is re-resolved and pinned into cURL for this connection.
 *  - a 4xx is not retried. The endpoint understood us and said no; five more
 *    attempts would only walk the counter towards auto-disable for free.
 *
 * @see docs/08-api-and-integrations.md §5 · docs/12-security-and-compliance.md §6
 */
final class DispatchWebhook extends TenantAwareJob
{
    /** One initial attempt plus the five backoff steps below. */
    public int $tries = 6;

    public int $timeout = 30;

    public function __construct(
        int $academyId,
        public readonly int $webhookId,
        public readonly int $deliveryId,
    ) {
        parent::__construct($academyId);
    }

    /**
     * 1m, 5m, 30m, 2h, 6h — docs/08 §5.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 21600];
    }

    public function handle(): void
    {
        $webhook = Webhook::query()->find($this->webhookId);
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if (! $webhook instanceof Webhook || ! $delivery instanceof WebhookDelivery) {
            return;
        }

        if ($delivery->status->isFinished()) {
            return;
        }

        if (! $webhook->isDeliverable()) {
            $delivery->markFailed(null, 'Webhook is disabled.', 0, permanent: true);

            return;
        }

        $body = $this->body($delivery);
        $startedAt = microtime(true);

        try {
            $safe = SafeUrl::validate((string) $webhook->url);
        } catch (InvalidArgumentException $e) {
            // An unsafe target is a configuration fault, not a transient one.
            $delivery->markFailed(null, $e->getMessage(), $this->elapsed($startedAt), permanent: true);
            $this->penalise($webhook, $e->getMessage());

            return;
        }

        $delivery->forceFill([
            'attempt' => (int) $delivery->attempt + 1,
            'dispatched_at' => now(),
        ])->save();

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'PTE-Webhook/1',
                'X-PTE-Event' => $delivery->event,
                'X-PTE-Delivery' => $delivery->delivery_id,
                WebhookSignature::HEADER => WebhookSignature::sign($body, (string) $webhook->secret),
            ])
                ->timeout(5)
                ->connectTimeout(5)
                ->withOptions(['curl' => [CURLOPT_RESOLVE => $safe->curlResolveEntries()]])
                ->withBody($body, 'application/json')
                ->post($safe->url);
        } catch (ConnectionException $e) {
            $this->recordAttemptFailure($webhook, $delivery, null, $e->getMessage(), $this->elapsed($startedAt));

            return;
        }

        $elapsed = $this->elapsed($startedAt);

        if ($response->successful()) {
            $delivery->markDelivered($response->status(), $response->body(), $elapsed);
            $webhook->recordSuccess();

            return;
        }

        // 4xx (bar 408/429) is a verdict, not a hiccup.
        $permanent = $response->status() >= 400
            && $response->status() < 500
            && ! in_array($response->status(), [408, 429], true);

        $this->recordAttemptFailure(
            $webhook,
            $delivery,
            $response->status(),
            'Endpoint responded with HTTP '.$response->status().'.',
            $elapsed,
            $permanent,
        );
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = WebhookDelivery::query()->withoutGlobalScope('academy')->find($this->deliveryId);

        if ($delivery instanceof WebhookDelivery && ! $delivery->status->isFinished()) {
            $delivery->forceFill([
                'status' => WebhookDeliveryStatus::Dead,
                'error' => mb_substr($exception?->getMessage() ?? 'Retries exhausted.', 0, 500),
            ])->save();
        }
    }

    private function recordAttemptFailure(
        Webhook $webhook,
        WebhookDelivery $delivery,
        ?int $status,
        string $error,
        int $elapsed,
        bool $permanent = false,
    ): void {
        $exhausted = $permanent || $this->attempts() >= $this->tries;

        $delivery->markFailed($status, $error, $elapsed, permanent: $exhausted);

        $this->penalise($webhook, $error);

        if ($exhausted) {
            return;
        }

        // Throwing (rather than release) keeps the configured backoff ladder.
        throw new RuntimeException("Webhook delivery {$delivery->delivery_id} failed: {$error}");
    }

    private function penalise(Webhook $webhook, string $error): void
    {
        if ($webhook->recordFailure($error)) {
            Log::warning('Webhook auto-disabled after consecutive failures.', [
                'academy_id' => $this->academyId,
                'webhook_id' => $webhook->getKey(),
                'failures' => Webhook::MAX_CONSECUTIVE_FAILURES,
            ]);
        }
    }

    private function body(WebhookDelivery $delivery): string
    {
        return (string) json_encode([
            'id' => $delivery->delivery_id,
            'event' => $delivery->event,
            'created_at' => $delivery->created_at?->toIso8601String(),
            'academy_id' => $this->academyId,
            'data' => $delivery->payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}

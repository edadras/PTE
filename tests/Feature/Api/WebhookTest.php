<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Integration\Enums\WebhookDeliveryStatus;
use App\Domain\Integration\Enums\WebhookEvent;
use App\Domain\Integration\Jobs\DispatchWebhook;
use App\Domain\Integration\Models\Webhook;
use App\Domain\Integration\Models\WebhookDelivery;
use App\Domain\Integration\Services\WebhookEmitter;
use App\Domain\Integration\Support\WebhookSignature;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

/**
 * Outbound webhooks — docs/08 §5.
 */
final class WebhookTest extends ApiTestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_signature_is_hmac_sha256_of_the_body(): void
    {
        $body = '{"event":"exam.scored"}';
        $secret = 'whsec_test';

        $signature = WebhookSignature::sign($body, $secret);

        $this->assertSame('sha256='.hash_hmac('sha256', $body, $secret), $signature);
        $this->assertTrue(WebhookSignature::verify($body, $secret, $signature));
        $this->assertFalse(WebhookSignature::verify($body.' ', $secret, $signature));
        $this->assertFalse(WebhookSignature::verify($body, 'other', $signature));
    }

    #[Test]
    public function a_webhook_signs_with_its_own_encrypted_secret(): void
    {
        $academy = $this->academy('alpha');

        $webhook = $this->webhookFor($academy);

        $this->assertSame(
            'sha256='.hash_hmac('sha256', '{"a":1}', 'whsec_alpha'),
            $webhook->sign('{"a":1}'),
        );

        // Encrypted at rest, and never serialised (docs/12 §2).
        $this->assertNotSame('whsec_alpha', $webhook->getRawOriginal('secret'));
        $this->assertArrayNotHasKey('secret', $webhook->toArray());
    }

    #[Test]
    public function emitting_creates_a_pending_delivery_and_queues_the_job(): void
    {
        Queue::fake();

        $academy = $this->academy('alpha');
        $this->webhookFor($academy);

        $queued = TenantContext::runFor($academy, fn (): int => app(WebhookEmitter::class)->emit(
            WebhookEvent::ExamScored,
            ['session_id' => 7],
        ));

        $this->assertSame(1, $queued);

        Queue::assertPushed(DispatchWebhook::class);

        $delivery = TenantContext::runFor(
            $academy,
            static fn (): ?WebhookDelivery => WebhookDelivery::query()->first()
        );

        $this->assertInstanceOf(WebhookDelivery::class, $delivery);
        $this->assertSame(WebhookEvent::ExamScored->value, $delivery->event);
        $this->assertSame(WebhookDeliveryStatus::Pending, $delivery->status);
        $this->assertSame(26, strlen((string) $delivery->delivery_id));
    }

    #[Test]
    public function an_unsubscribed_event_is_not_delivered(): void
    {
        Queue::fake();

        $academy = $this->academy('alpha');
        $this->webhookFor($academy, [WebhookEvent::StudentCreated->value]);

        $queued = TenantContext::runFor($academy, fn (): int => app(WebhookEmitter::class)->emit(
            WebhookEvent::ExamScored,
            [],
        ));

        $this->assertSame(0, $queued);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function another_academys_webhook_never_receives_the_event(): void
    {
        Queue::fake();

        $alpha = $this->academy('alpha');
        $beta = $this->academy('beta');

        $this->webhookFor($beta);

        $queued = TenantContext::runFor($alpha, fn (): int => app(WebhookEmitter::class)->emit(
            WebhookEvent::ExamScored,
            [],
        ));

        $this->assertSame(0, $queued);
    }

    #[Test]
    public function a_delivery_carries_the_three_documented_headers(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $academy = $this->academy('alpha');

        // A literal public address: SafeUrl short-circuits DNS for an IP host,
        // so the test exercises the real guard without touching a resolver.
        $webhook = $this->webhookFor($academy, url: 'https://93.184.216.34/hooks/pte');

        $delivery = TenantContext::runFor($academy, static function () use ($webhook): WebhookDelivery {
            /** @var WebhookDelivery $delivery */
            $delivery = WebhookDelivery::query()->create([
                'webhook_id' => $webhook->getKey(),
                'event' => WebhookEvent::ExamScored->value,
                'payload' => ['score_id' => 42],
                'status' => WebhookDeliveryStatus::Pending,
            ]);

            (new DispatchWebhook(
                (int) $webhook->academy_id,
                (int) $webhook->getKey(),
                (int) $delivery->getKey(),
            ))->handle();

            return $delivery->refresh();
        });

        $this->assertSame(WebhookDeliveryStatus::Delivered, $delivery->status);
        $this->assertSame(200, $delivery->response_status);

        Http::assertSent(function ($request) use ($delivery): bool {
            $signature = $request->header('X-PTE-Signature')[0] ?? '';

            return $request->header('X-PTE-Event')[0] === WebhookEvent::ExamScored->value
                && $request->header('X-PTE-Delivery')[0] === $delivery->delivery_id
                && WebhookSignature::verify($request->body(), 'whsec_alpha', $signature);
        });
    }

    #[Test]
    public function twenty_consecutive_failures_disable_the_endpoint(): void
    {
        $academy = $this->academy('alpha');
        $webhook = $this->webhookFor($academy);

        TenantContext::runFor($academy, function () use ($webhook): void {
            for ($i = 1; $i < Webhook::MAX_CONSECUTIVE_FAILURES; $i++) {
                $this->assertFalse($webhook->recordFailure('boom'));
            }

            $this->assertTrue($webhook->recordFailure('boom'));
        });

        $this->assertFalse($webhook->fresh()?->isDeliverable());
    }

    #[Test]
    public function a_private_address_is_refused_by_the_shared_ssrf_guard(): void
    {
        $academy = $this->academy('alpha');
        ['plain_text' => $token] = $this->apiKeyFor($academy, ['*']);

        $this->postJson('/api/v1/webhooks', [
            'name' => 'internal',
            'url' => 'https://127.0.0.1/hook',
            'events' => [WebhookEvent::ExamScored->value],
        ], $this->keyHeaders($token))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    /**
     * @param  array<int, string>|null  $events
     */
    private function webhookFor(
        Academy $academy,
        ?array $events = null,
        string $url = 'https://crm.example.test/hooks/pte',
    ): Webhook {
        return TenantContext::runFor($academy, static fn (): Webhook => Webhook::query()->create([
            'name' => 'crm',
            'url' => $url,
            'secret' => 'whsec_alpha',
            'events' => $events ?? [WebhookEvent::ExamScored->value],
            'is_active' => true,
        ]));
    }
}

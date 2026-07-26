<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Integration\Enums\WebhookDeliveryStatus;
use App\Domain\Integration\Enums\WebhookEvent;
use App\Domain\Integration\Models\WebhookDelivery;
use Database\Factories\Concerns\ResolvesAcademy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookDelivery>
 */
final class WebhookDeliveryFactory extends Factory
{
    use ResolvesAcademy;

    protected $model = WebhookDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academy_id' => $this->resolveAcademy(),
            'webhook_id' => WebhookFactory::new(),
            'delivery_id' => (string) Str::ulid(),
            'event' => WebhookEvent::StudentCreated,
            'payload' => [
                'event' => WebhookEvent::StudentCreated->value,
                'data' => ['student_id' => 1],
            ],
            'status' => WebhookDeliveryStatus::Pending,
            'attempt' => 0,
            'dispatched_at' => null,
            'delivered_at' => null,
            'next_attempt_at' => null,
        ];
    }

    public function forEvent(WebhookEvent $event): self
    {
        return $this->state(fn (array $attributes): array => [
            'event' => $event,
            'payload' => [...($attributes['payload'] ?? []), 'event' => $event->value],
        ]);
    }

    public function delivered(int $responseStatus = 200): self
    {
        return $this->state(fn (): array => [
            'status' => WebhookDeliveryStatus::Delivered,
            'attempt' => 1,
            'response_status' => $responseStatus,
            'duration_ms' => 120,
            'dispatched_at' => now()->subSecond(),
            'delivered_at' => now(),
        ]);
    }

    public function failed(?int $responseStatus = 500): self
    {
        return $this->state(fn (): array => [
            'status' => WebhookDeliveryStatus::Failed,
            'attempt' => 1,
            'response_status' => $responseStatus,
            'error' => 'Server error',
            'duration_ms' => 300,
            'dispatched_at' => now()->subSecond(),
            'next_attempt_at' => now()->addMinutes(5),
        ]);
    }

    public function dead(): self
    {
        return $this->state(fn (): array => [
            'status' => WebhookDeliveryStatus::Dead,
            'attempt' => 6,
            'error' => 'Retries exhausted',
            'dispatched_at' => now()->subHour(),
            'next_attempt_at' => null,
        ]);
    }
}

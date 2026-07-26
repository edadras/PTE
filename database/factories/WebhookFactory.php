<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Integration\Enums\WebhookEvent;
use App\Domain\Integration\Models\Webhook;
use Database\Factories\Concerns\ResolvesAcademy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Webhook>
 */
final class WebhookFactory extends Factory
{
    use ResolvesAcademy;

    protected $model = Webhook::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academy_id' => $this->resolveAcademy(),
            'name' => fake()->company().' CRM',
            'url' => 'https://'.fake()->unique()->domainName().'/hooks/pte',
            'secret' => Str::random(40),
            // Empty means "every event" (Webhook::subscribesTo).
            'events' => [],
            'is_active' => true,
            'consecutive_failures' => 0,
            'delivered_count' => 0,
            'failed_count' => 0,
        ];
    }

    /**
     * @param  array<int, WebhookEvent|string>  $events
     */
    public function forEvents(array $events): self
    {
        return $this->state(fn (): array => [
            'events' => array_map(
                static fn (WebhookEvent|string $event): string => $event instanceof WebhookEvent ? $event->value : $event,
                $events,
            ),
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function disabled(): self
    {
        return $this->state(fn (): array => [
            'is_active' => false,
            'consecutive_failures' => Webhook::MAX_CONSECUTIVE_FAILURES,
            'disabled_at' => now(),
            'disabled_reason' => 'consecutive_failures',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\Student;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Models\Notification;
use Database\Factories\Concerns\ResolvesAcademy;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Notification>
 */
final class NotificationFactory extends Factory
{
    use ResolvesAcademy;

    protected $model = Notification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academy_id' => $this->resolveAcademy(),
            'notifiable_type' => Student::class,
            'notifiable_id' => 1,
            'channel' => NotificationChannel::Telegram,
            'type' => 'daily_nudge',
            'data' => [],
            'status' => NotificationStatus::Pending,
            'attempts' => 0,
        ];
    }

    /** Not named for(): Factory::for() is untyped and cannot be narrowed. */
    public function forNotifiable(Model $notifiable): self
    {
        return $this->state(fn (): array => [
            'notifiable_type' => $notifiable::class,
            'notifiable_id' => $notifiable->getKey(),
        ]);
    }

    public function channel(NotificationChannel $channel): self
    {
        return $this->state(fn (): array => ['channel' => $channel]);
    }

    public function sent(): self
    {
        return $this->state(fn (): array => [
            'status' => NotificationStatus::Sent,
            'sent_at' => now(),
            'attempts' => 1,
        ]);
    }

    public function scheduledFor(DateTimeInterface $moment): self
    {
        return $this->state(fn (): array => [
            'scheduled_at' => $moment,
            'status' => NotificationStatus::Queued,
        ]);
    }
}

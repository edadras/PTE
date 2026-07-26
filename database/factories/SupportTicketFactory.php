<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketSource;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\SupportTicket;
use Database\Factories\Concerns\ResolvesAcademy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicket>
 */
final class SupportTicketFactory extends Factory
{
    use ResolvesAcademy;

    protected $model = SupportTicket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academy_id' => $this->resolveAcademy(),
            // student_id belongs to the Identity context; tests set it to a row
            // they created themselves.
            'student_id' => null,
            'subject' => $this->faker->sentence(4),
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Normal,
            'source' => TicketSource::Telegram,
            'assigned_to' => null,
            'last_reply_at' => now(),
        ];
    }

    public function forStudent(int $studentId): self
    {
        return $this->state(fn (): array => ['student_id' => $studentId]);
    }

    public function closed(): self
    {
        return $this->state(fn (): array => [
            'status' => TicketStatus::Closed,
            'closed_at' => now(),
        ]);
    }

    public function pending(): self
    {
        return $this->state(fn (): array => ['status' => TicketStatus::Pending]);
    }

    public function priority(TicketPriority $priority): self
    {
        return $this->state(fn (): array => ['priority' => $priority]);
    }
}

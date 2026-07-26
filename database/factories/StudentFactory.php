<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Enums\StudentSource;
use App\Domain\Identity\Enums\StudentStatus;
use App\Domain\Identity\Models\Student;
use Database\Factories\Concerns\ResolvesAcademy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Student>
 */
final class StudentFactory extends Factory
{
    use ResolvesAcademy;

    protected $model = Student::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Lets `Student::factory()->create()` stand on its own in tests;
            // `->for($academy)` or `->has()` overrides it.
            'academy_id' => $this->resolveAcademy(),
            'student_code' => 'ST'.Str::upper(Str::random(6)),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('09#########'),
            'locale' => 'fa',
            'level' => fake()->randomElement(['A2', 'B1', 'B2', 'C1']),
            'target_score' => fake()->randomElement([65, 70, 79, 90]),
            'status' => StudentStatus::Active,
            'source' => StudentSource::Telegram,
            'registered_at' => now(),
            'last_active_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => StudentStatus::Inactive]);
    }

    public function blocked(): static
    {
        return $this->state(fn (): array => ['status' => StudentStatus::Blocked]);
    }

    public function subscribed(int $days = 30): static
    {
        return $this->state(fn (): array => [
            'subscription_status' => 'active',
            'subscription_expires_at' => now()->addDays($days),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Enums\ClassGroupStatus;
use App\Domain\Identity\Models\ClassGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassGroup>
 */
final class ClassGroupFactory extends Factory
{
    use \Database\Factories\Concerns\ResolvesAcademy;

    protected $model = ClassGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academy_id' => $this->resolveAcademy(),
            'name' => fake()->words(2, true).' '.fake()->randomElement(['A1', 'B2', 'Intensive']),
            'course_id' => null,
            'teacher_id' => null,
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->addMonths(2),
            'capacity' => fake()->numberBetween(8, 25),
            'status' => ClassGroupStatus::Active,
        ];
    }

    public function taughtBy(User $teacher): static
    {
        return $this->state(fn (): array => ['teacher_id' => $teacher->getKey()]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => ClassGroupStatus::Completed,
            'ends_at' => now()->subDay(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lesson>
 */
final class LessonFactory extends Factory
{
    protected $model = Lesson::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'title' => rtrim($this->faker->sentence(4), '.'),
            'content' => [
                'blocks' => [
                    ['type' => 'text', 'value' => $this->faker->paragraph()],
                ],
            ],
            'media_path' => null,
            'duration_minutes' => $this->faker->numberBetween(5, 45),
            'sort_order' => 0,
            'is_free' => false,
            'status' => CourseStatus::Draft,
        ];
    }

    public function published(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => CourseStatus::Published]);
    }

    public function free(): self
    {
        return $this->state(fn (array $attributes): array => ['is_free' => true]);
    }
}

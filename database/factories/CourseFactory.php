<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Course>
 */
final class CourseFactory extends Factory
{
    protected $model = Course::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = $this->faker->unique()->sentence(3);

        return [
            'title' => rtrim($title, '.'),
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'description' => $this->faker->paragraph(),
            'module_key' => $this->faker->randomElement([ModuleKey::PteSpeaking, ModuleKey::PteListening]),
            'cover_path' => null,
            'price' => $this->faker->randomElement([0, 1_500_000, 3_000_000]),
            'currency' => 'IRR',
            'duration_days' => $this->faker->randomElement([30, 60, 90]),
            'status' => CourseStatus::Draft,
            'sort_order' => 0,
        ];
    }

    public function published(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => CourseStatus::Published]);
    }

    public function free(): self
    {
        return $this->state(fn (array $attributes): array => ['price' => 0]);
    }
}

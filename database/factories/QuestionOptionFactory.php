<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionOption>
 */
final class QuestionOptionFactory extends Factory
{
    protected $model = QuestionOption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question_id' => Question::factory(),
            'option_key' => strtoupper($this->faker->unique()->randomLetter()),
            'text' => rtrim($this->faker->sentence(6), '.'),
            'is_correct' => false,
            'sort_order' => 0,
            'explanation' => null,
        ];
    }

    public function correct(): self
    {
        return $this->state(fn (array $attributes): array => ['is_correct' => true]);
    }

    public function key(string $key, int $sortOrder = 0): self
    {
        return $this->state(fn (array $attributes): array => [
            'option_key' => strtoupper($key),
            'sort_order' => $sortOrder,
        ]);
    }
}

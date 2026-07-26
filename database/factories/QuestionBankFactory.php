<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Models\QuestionBank;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionBank>
 */
final class QuestionBankFactory extends Factory
{
    protected $model = QuestionBank::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Bank '.$this->faker->unique()->word(),
            'module_key' => ModuleKey::PteListening,
            'description' => $this->faker->sentence(),
            'is_default' => false,
            'question_count' => 0,
        ];
    }

    public function default(): self
    {
        return $this->state(fn (array $attributes): array => [
            'is_default' => true,
            'name' => 'Main Bank',
        ]);
    }

    public function forModule(ModuleKey $module): self
    {
        return $this->state(fn (array $attributes): array => ['module_key' => $module]);
    }
}

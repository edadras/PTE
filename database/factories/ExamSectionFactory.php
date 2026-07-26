<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Assessment\Enums\SelectionMode;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamSection;
use App\Domain\Learning\Enums\ModuleKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamSection>
 */
final class ExamSectionFactory extends Factory
{
    protected $model = ExamSection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_id' => Exam::factory(),
            'title' => 'Listening',
            'module_key' => ModuleKey::PteListening,
            'duration_minutes' => 30,
            'score' => 20,
            'sort_order' => 0,
            'selection_mode' => SelectionMode::Manual,
            'selection_config' => null,
        ];
    }

    public function random(int $count = 5, ?string $difficulty = null): self
    {
        return $this->state(fn (): array => [
            'selection_mode' => SelectionMode::Random,
            'selection_config' => array_filter([
                'count' => $count,
                'difficulty' => $difficulty,
            ]),
        ]);
    }

    public function pool(int $take = 10): self
    {
        return $this->state(fn (): array => [
            'selection_mode' => SelectionMode::Pool,
            'selection_config' => ['take' => $take],
        ]);
    }

    public function forModule(ModuleKey $module): self
    {
        return $this->state(fn (): array => [
            'module_key' => $module,
            'title' => $module->label(),
        ]);
    }
}

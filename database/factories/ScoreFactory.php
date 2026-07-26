<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Models\Score;
use App\Domain\Learning\Enums\ModuleKey;
use Database\Factories\Concerns\ResolvesAcademy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Score>
 */
final class ScoreFactory extends Factory
{
    use ResolvesAcademy;

    protected $model = Score::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academy_id' => $this->resolveAcademy(),
            // student_id belongs to the Identity context, which this factory
            // must not reach into; tests set it to a row they created themselves.
            'student_id' => 1,
            'session_type' => SessionType::Practice,
            'session_id' => 1,
            'module_key' => null,
            'raw_score' => 45.0,
            'scaled_score' => null,
            'percentage' => 50.0,
            'breakdown' => null,
            'published_at' => null,
        ];
    }

    public function forSession(SessionType $type, int $sessionId): self
    {
        return $this->state(fn (): array => [
            'session_type' => $type,
            'session_id' => $sessionId,
        ]);
    }

    public function forModule(ModuleKey $module): self
    {
        return $this->state(fn (): array => ['module_key' => $module]);
    }

    public function published(): self
    {
        return $this->state(fn (): array => ['published_at' => now()]);
    }
}

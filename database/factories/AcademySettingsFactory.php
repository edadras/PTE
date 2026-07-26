<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Tenancy\Models\AcademySettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademySettings>
 */
final class AcademySettingsFactory extends Factory
{
    use \Database\Factories\Concerns\ResolvesAcademy;

    protected $model = AcademySettings::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academy_id' => $this->resolveAcademy(),
            'locale' => 'fa',
            'currency' => 'IRR',
            'practice_config' => ['daily_goal' => 10, 'allow_retry' => true],
            'exam_config' => ['shuffle_questions' => true],
            'notification_config' => ['daily_nudge' => true],
            'features' => [],
            'data_retention_days' => 365,
        ];
    }

    public function locale(string $locale): static
    {
        return $this->state(fn (): array => ['locale' => $locale]);
    }
}

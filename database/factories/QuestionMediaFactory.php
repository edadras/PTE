<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Learning\Enums\MediaKind;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionMedia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionMedia>
 */
final class QuestionMediaFactory extends Factory
{
    protected $model = QuestionMedia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question_id' => Question::factory(),
            'kind' => MediaKind::Audio,
            's3_path' => 'questions/'.$this->faker->uuid().'/audio.mp3',
            'mime' => 'audio/mpeg',
            'size_bytes' => $this->faker->numberBetween(20_000, 900_000),
            'duration_ms' => $this->faker->numberBetween(3_000, 90_000),
            'transcript' => null,
            'telegram_file_id' => null,
            'telegram_bot_id' => null,
            'cached_at' => null,
        ];
    }

    public function image(): self
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => MediaKind::Image,
            's3_path' => 'questions/'.$this->faker->uuid().'/image.png',
            'mime' => 'image/png',
            'duration_ms' => null,
        ]);
    }

    public function video(): self
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => MediaKind::Video,
            's3_path' => 'questions/'.$this->faker->uuid().'/clip.mp4',
            'mime' => 'video/mp4',
        ]);
    }

    public function cachedOnBot(int $botId, string $fileId): self
    {
        return $this->state(fn (array $attributes): array => [
            'telegram_bot_id' => $botId,
            'telegram_file_id' => $fileId,
            'cached_at' => now(),
        ]);
    }
}

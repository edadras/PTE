<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Telegram\Models\TelegramIdentity;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramIdentity>
 */
final class TelegramIdentityFactory extends Factory
{
    protected $model = TelegramIdentity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $userId = fake()->unique()->numberBetween(10_000_000, 9_999_999_999);

        return [
            'academy_id' => Academy::factory(),
            'student_id' => null,
            'telegram_user_id' => $userId,
            // In a private chat Telegram uses the user id as the chat id.
            'chat_id' => $userId,
            'username' => fake()->optional()->userName(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->optional()->lastName(),
            'language_code' => fake()->randomElement(['fa', 'en']),
            'is_bot' => false,
            'is_blocked' => false,
            'last_interaction_at' => now(),
        ];
    }

    public function blocked(): self
    {
        return $this->state(fn (): array => [
            'is_blocked' => true,
            'blocked_at' => now()->subDay(),
        ]);
    }

    public function linked(int $studentId): self
    {
        return $this->state(fn (): array => [
            'student_id' => $studentId,
            'linked_at' => now(),
        ]);
    }

    public function consented(): self
    {
        return $this->state(fn (): array => [
            'consented_at' => now(),
            'consent_version' => '1.0',
        ]);
    }
}

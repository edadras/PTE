<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Telegram\Enums\BotHealthStatus;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TelegramBot>
 */
final class TelegramBotFactory extends Factory
{
    protected $model = TelegramBot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = fake()->numerify('##########').':'.Str::random(35);

        return [
            'academy_id' => Academy::factory(),
            'public_id' => (string) Str::ulid(),
            'token' => $token,
            'token_last4' => mb_substr($token, -4),
            'bot_user_id' => fake()->unique()->numberBetween(100_000_000, 9_999_999_999),
            'username' => fake()->unique()->userName().'_bot',
            'first_name' => fake()->company(),
            'webhook_secret' => Str::random(32),
            'webhook_url' => null,
            'webhook_registered_at' => null,
            'is_active' => false,
            'health_status' => BotHealthStatus::Ok,
            'consecutive_failures' => 0,
            'pending_update_count' => 0,
        ];
    }

    public function active(): self
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => true,
            'webhook_registered_at' => now(),
            'webhook_url' => rtrim((string) config('pte.platform.webhook_base_url'), '/')
                .'/webhook/'.($attributes['public_id'] ?? Str::ulid()),
        ]);
    }

    public function degraded(): self
    {
        return $this->state(fn (): array => [
            'health_status' => BotHealthStatus::Degraded,
            'consecutive_failures' => 1,
            'last_error' => 'Wrong response from the webhook: 500 Internal Server Error',
            'last_error_at' => now()->subMinutes(5),
        ]);
    }

    public function failing(): self
    {
        return $this->state(fn (): array => [
            'health_status' => BotHealthStatus::Failing,
            'consecutive_failures' => 3,
            'last_error' => 'Connection timed out',
            'last_error_at' => now()->subMinutes(2),
        ]);
    }
}

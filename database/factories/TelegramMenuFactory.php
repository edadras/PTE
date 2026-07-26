<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Telegram\Enums\MenuType;
use App\Domain\Telegram\Enums\PublishStatus;
use App\Domain\Telegram\Models\TelegramMenu;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramMenu>
 */
final class TelegramMenuFactory extends Factory
{
    protected $model = TelegramMenu::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academy_id' => Academy::factory(),
            'name' => fake()->words(2, true),
            'key' => fake()->unique()->slug(2),
            'type' => MenuType::Main,
            'status' => PublishStatus::Draft,
            'version' => 1,
            'is_active' => false,
            'header_text' => null,
        ];
    }

    public function published(): self
    {
        return $this->state(fn (): array => [
            'status' => PublishStatus::Published,
            'is_active' => true,
            'published_at' => now(),
        ]);
    }

    public function ofType(MenuType $type): self
    {
        return $this->state(fn (): array => ['type' => $type]);
    }
}

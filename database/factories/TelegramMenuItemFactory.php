<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Telegram\Enums\MenuActionType;
use App\Domain\Telegram\Models\TelegramMenu;
use App\Domain\Telegram\Models\TelegramMenuItem;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramMenuItem>
 */
final class TelegramMenuItemFactory extends Factory
{
    protected $model = TelegramMenuItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academy_id' => Academy::factory(),
            'menu_id' => TelegramMenu::factory(),
            'parent_id' => null,
            'label' => fake()->words(2, true),
            'icon' => fake()->randomElement(['🏠', '📚', '📝', '🎙', '👤', '💳', '📞']),
            'action_type' => MenuActionType::SendMessage,
            'action_payload' => ['text' => fake()->sentence()],
            'visibility_rule' => null,
            'row' => 0,
            'column' => 0,
            'sort_order' => fake()->numberBetween(0, 20),
            'is_enabled' => true,
        ];
    }

    public function disabled(): self
    {
        return $this->state(fn (): array => ['is_enabled' => false]);
    }

    public function link(string $url = 'https://example.com'): self
    {
        return $this->state(fn (): array => [
            'action_type' => MenuActionType::OpenUrl,
            'action_payload' => ['url' => $url],
        ]);
    }

    public function practice(string $type = 'RA'): self
    {
        return $this->state(fn (): array => [
            'action_type' => MenuActionType::StartPractice,
            'action_payload' => ['type' => $type, 'count' => 5],
        ]);
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    public function withVisibility(array $rule): self
    {
        return $this->state(fn (): array => ['visibility_rule' => $rule]);
    }

    public function atGrid(int $row, int $column): self
    {
        return $this->state(fn (): array => ['row' => $row, 'column' => $column]);
    }
}

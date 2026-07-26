<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Tenancy\Enums\DarkMode;
use App\Domain\Tenancy\Models\AcademyBrand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AcademyBrand>
 */
final class AcademyBrandFactory extends Factory
{
    use \Database\Factories\Concerns\ResolvesAcademy;

    protected $model = AcademyBrand::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'academy_id' => $this->resolveAcademy(),
            'display_name' => $name,
            'short_name' => Str::limit($name, 40, ''),
            'tagline' => fake()->catchPhrase(),
            'primary_color' => '#2563EB',
            'secondary_color' => '#7C3AED',
            'accent_color' => '#0EA5E9',
            'success_color' => '#16A34A',
            'danger_color' => '#DC2626',
            'dark_mode' => DarkMode::Auto,
            'font_family' => 'Vazirmatn',
            'welcome_text' => 'سلام {first_name} عزیز 👋',
            'footer_text' => null,
            'support_email' => fake()->safeEmail(),
            'default_locale' => 'fa',
            'supported_locales' => ['fa', 'en'],
        ];
    }

    public function dark(): static
    {
        return $this->state(fn (): array => ['dark_mode' => DarkMode::Dark]);
    }

    public function withAssets(): static
    {
        return $this->state(fn (): array => [
            'logo_light_path' => 'brand/logo-light.png',
            'logo_dark_path' => 'brand/logo-dark.png',
            'icon_path' => 'brand/icon.png',
            'welcome_image_path' => 'brand/welcome.png',
        ]);
    }
}

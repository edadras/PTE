<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Tenancy\Models\AcademyBrand;
use Illuminate\Http\Request;

/**
 * What the web app injects into CSS variables before first paint (docs/08 §6).
 *
 * @mixin AcademyBrand
 */
final class BrandResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'display_name' => $this->display_name,
            'short_name' => $this->shortName(),
            'logo_url' => $this->logoUrl(),
            'logo_dark_url' => $this->logoUrl(dark: true),
            'icon_url' => $this->iconUrl(),
            'welcome_image_url' => $this->welcomeImageUrl(),
            'palette' => $this->colorPalette(),
            'default_locale' => $this->default_locale,
            'supported_locales' => $this->supportedLocales(),
        ];
    }
}

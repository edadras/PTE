<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Tenancy\Models\Academy;
use Illuminate\Http\Request;

/**
 * Platform-tier view of a tenant.
 *
 * @mixin Academy
 */
final class AcademyResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // preferredLocale() reads two relations as properties, which
        // Model::shouldBeStrict() forbids lazily. Loading them explicitly is
        // allowed and is a no-op when the controller already eager-loaded.
        $this->resource->loadMissing(['settings', 'brand']);

        return [
            'id' => (int) $this->getKey(),
            'slug' => $this->slug,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'status' => $this->status->value,
            'plan_id' => $this->plan_id === null ? null : (int) $this->plan_id,
            'owner_user_id' => $this->owner_user_id === null ? null : (int) $this->owner_user_id,
            'timezone' => $this->timezone,
            'country' => $this->country,
            'locale' => $this->preferredLocale(),
            'primary_domain' => $this->primaryDomain()?->hostname,
            'modules' => $this->enabledModuleKeys(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

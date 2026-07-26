<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Integration\Models\Webhook;
use Illuminate\Http\Request;

/**
 * The secret is returned exactly once, on creation, via `additional()`.
 *
 * @mixin Webhook
 */
final class WebhookResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'name' => $this->name,
            'url' => $this->url,
            'events' => $this->events ?? [],
            'is_active' => (bool) $this->is_active,
            'consecutive_failures' => (int) $this->consecutive_failures,
            'delivered_count' => (int) $this->delivered_count,
            'failed_count' => (int) $this->failed_count,
            'last_success_at' => $this->last_success_at?->toIso8601String(),
            'last_failure_at' => $this->last_failure_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'disabled_at' => $this->disabled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

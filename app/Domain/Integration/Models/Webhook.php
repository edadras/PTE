<?php

declare(strict_types=1);

namespace App\Domain\Integration\Models;

use App\Domain\Integration\Enums\WebhookEvent;
use App\Domain\Integration\Support\WebhookSignature;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An academy-owned outbound endpoint.
 *
 * @property int $academy_id
 * @property string $url
 * @property string $secret
 * @property array<int, string> $events
 * @property bool $is_active
 * @property int $consecutive_failures
 *
 * @see docs/08-api-and-integrations.md §5
 */
final class Webhook extends Model
{
    use BelongsToAcademy;

    /** Twenty consecutive failures means the endpoint is gone, not flaky. */
    public const MAX_CONSECUTIVE_FAILURES = 20;

    protected $guarded = ['id'];

    /** The secret is an HMAC key: it may leave the server exactly once. */
    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
            'consecutive_failures' => 'integer',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDeliverable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('disabled_at');
    }

    public function isDeliverable(): bool
    {
        return $this->is_active && $this->disabled_at === null;
    }

    public function subscribesTo(WebhookEvent|string $event): bool
    {
        $value = $event instanceof WebhookEvent ? $event->value : $event;
        $events = $this->events ?? [];

        return $events === [] || in_array($value, $events, true) || in_array('*', $events, true);
    }

    public function sign(string $body): string
    {
        return WebhookSignature::sign($body, (string) $this->secret);
    }

    public function recordSuccess(): void
    {
        $this->forceFill([
            'consecutive_failures' => 0,
            'delivered_count' => (int) $this->delivered_count + 1,
            'last_success_at' => now(),
            'last_error' => null,
        ])->save();
    }

    /** @return bool true when this failure disabled the endpoint */
    public function recordFailure(string $error): bool
    {
        $failures = (int) $this->consecutive_failures + 1;
        $disabled = $failures >= self::MAX_CONSECUTIVE_FAILURES;

        $this->forceFill(array_filter([
            'consecutive_failures' => $failures,
            'failed_count' => (int) $this->failed_count + 1,
            'last_failure_at' => now(),
            'last_error' => mb_substr($error, 0, 500),
            'disabled_at' => $disabled ? now() : null,
            'disabled_reason' => $disabled ? 'consecutive_failures' : null,
            'is_active' => $disabled ? false : $this->is_active,
        ], static fn (mixed $value): bool => $value !== null))->save();

        return $disabled;
    }
}

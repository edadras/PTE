<?php

declare(strict_types=1);

namespace App\Domain\Integration\Models;

use App\Domain\Integration\Enums\WebhookDeliveryStatus;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $academy_id
 * @property int $webhook_id
 * @property string $delivery_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property WebhookDeliveryStatus $status
 *
 * @see docs/08-api-and-integrations.md §5
 */
final class WebhookDelivery extends Model
{
    use BelongsToAcademy;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => WebhookDeliveryStatus::class,
            'attempt' => 'integer',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $delivery): void {
            if (blank($delivery->delivery_id)) {
                $delivery->delivery_id = (string) Str::ulid();
            }
        });
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', WebhookDeliveryStatus::Pending->value);
    }

    public function markDelivered(int $responseStatus, ?string $body, int $durationMs): void
    {
        $this->forceFill([
            'status' => WebhookDeliveryStatus::Delivered,
            'response_status' => $responseStatus,
            'response_body' => $body === null ? null : mb_substr($body, 0, 2000),
            'duration_ms' => $durationMs,
            'delivered_at' => now(),
            'error' => null,
            'next_attempt_at' => null,
        ])->save();
    }

    public function markFailed(
        ?int $responseStatus,
        string $error,
        int $durationMs,
        bool $permanent = false,
    ): void {
        $this->forceFill([
            'status' => $permanent ? WebhookDeliveryStatus::Dead : WebhookDeliveryStatus::Failed,
            'response_status' => $responseStatus,
            'error' => mb_substr($error, 0, 500),
            'duration_ms' => $durationMs,
        ])->save();
    }
}

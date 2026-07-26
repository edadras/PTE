<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Our own outbox, not Laravel's `notifications` table.
 *
 * The extra columns are the point: channel, scheduled time, delivery status and
 * the provider error. "Did the student actually receive the reminder?" is a
 * daily support question, and Laravel's own table cannot answer it.
 *
 * @property int $id
 * @property int $academy_id
 * @property string $notifiable_type
 * @property int $notifiable_id
 * @property NotificationChannel $channel
 * @property string $type
 * @property array<string, mixed>|null $data
 * @property NotificationStatus $status
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $read_at
 *
 * @see docs/07-database-schema.md §10
 */
final class Notification extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => NotificationStatus::class,
            'notifiable_id' => 'integer',
            'data' => 'array',
            'attempts' => 'integer',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    /** Models live under app/Domain, so the default factory guesser misses. */
    protected static function newFactory(): NotificationFactory
    {
        return NotificationFactory::new();
    }

    /** @return MorphTo<Model, $this> */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDue(Builder $query, ?Carbon $moment = null): Builder
    {
        $moment ??= now();

        return $query
            ->whereIn('status', [NotificationStatus::Pending->value, NotificationStatus::Queued->value])
            ->where(fn (Builder $inner): Builder => $inner
                ->whereNull('scheduled_at')
                ->orWhere('scheduled_at', '<=', $moment));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function markSent(): void
    {
        $this->forceFill([
            'status' => NotificationStatus::Sent,
            'sent_at' => now(),
            'attempts' => (int) $this->attempts + 1,
            'error' => null,
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => NotificationStatus::Failed,
            'attempts' => (int) $this->attempts + 1,
            'error' => mb_substr($error, 0, 1000),
        ])->save();
    }

    public function markSkipped(string $reason): void
    {
        $this->forceFill([
            'status' => NotificationStatus::Skipped,
            'error' => mb_substr($reason, 0, 1000),
        ])->save();
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }
}

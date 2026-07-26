<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Models;

use App\Domain\Telegram\Enums\BroadcastStatus;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $academy_id
 * @property array<string, mixed> $content
 * @property array<string, mixed>|null $audience_filter
 * @property BroadcastStatus $status
 * @property int $total
 * @property int $sent
 * @property int $failed
 * @property int $blocked
 */
final class Broadcast extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'audience_filter' => 'array',
            'status' => BroadcastStatus::class,
            'total' => 'integer',
            'sent' => 'integer',
            'failed' => 'integer',
            'blocked' => 'integer',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function progressPercent(): int
    {
        if ($this->total <= 0) {
            return 0;
        }

        $done = $this->sent + $this->failed + $this->blocked;

        return (int) min(100, round($done / $this->total * 100));
    }

    /** Atomic so parallel batch jobs do not lose counts. */
    public function recordOutcome(string $counter, int $amount = 1): void
    {
        if (! in_array($counter, ['sent', 'failed', 'blocked'], true)) {
            return;
        }

        $this->newQuery()->whereKey($this->getKey())->increment($counter, $amount);
    }

    public function isCancelled(): bool
    {
        return $this->status === BroadcastStatus::Cancelled;
    }

    /** @param  Builder<self>  $query */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', BroadcastStatus::Scheduled)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now());
    }
}

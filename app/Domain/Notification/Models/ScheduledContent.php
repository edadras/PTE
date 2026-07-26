<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Notification\Enums\ScheduledContentStatus;
use App\Domain\Notification\Enums\ScheduledContentType;
use App\Domain\Notification\Support\RepeatRule;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A thing to do at a stated wall-clock time.
 *
 * `scheduled_at` is UTC, `timezone` is the academy's. Both are needed and
 * neither is redundant: the UTC column is what the every-minute sweep compares
 * against, and the zone is what the next occurrence of a repeating rule is
 * computed in. Keeping only the instant would make a daily 09:00 rule drift by
 * an hour at every DST change (docs/05 §6).
 *
 * @property int $id
 * @property int $academy_id
 * @property ScheduledContentType $type
 * @property int|null $target_id
 * @property array<string, mixed>|null $audience
 * @property array<string, mixed>|null $payload
 * @property Carbon $scheduled_at
 * @property string|null $repeat_rule
 * @property string $timezone
 * @property ScheduledContentStatus $status
 * @property Carbon|null $executed_at
 * @property Carbon|null $last_run_at
 * @property int $run_count
 *
 * @see docs/07-database-schema.md §10
 */
final class ScheduledContent extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ScheduledContentType::class,
            'status' => ScheduledContentStatus::class,
            'target_id' => 'integer',
            'audience' => 'array',
            'payload' => 'array',
            'run_count' => 'integer',
            'scheduled_at' => 'datetime',
            'executed_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * Rows whose moment has arrived.
     *
     * Deliberately not scoped by academy: the sweep runs once for the whole
     * platform and re-enters each tenant per row (docs/10 §2).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDue(Builder $query, ?Carbon $moment = null): Builder
    {
        return $query
            ->where('status', ScheduledContentStatus::Pending->value)
            ->where('scheduled_at', '<=', $moment ?? now());
    }

    /**
     * Build a row from a wall-clock time the academy chose.
     *
     * The only correct way to create one of these: a naive "09:00" is
     * meaningless until it is anchored to a zone, and doing that conversion at
     * every call site is how the server's timezone leaks in.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function scheduleLocal(
        Academy $academy,
        ScheduledContentType $type,
        string $localDateTime,
        ?string $repeatRule = null,
        array $attributes = [],
    ): self {
        $timezone = self::timezoneOf($academy);

        /** @var self $content */
        $content = self::query()->create([
            'academy_id' => $academy->getKey(),
            'type' => $type,
            'timezone' => $timezone,
            'scheduled_at' => Carbon::parse($localDateTime, $timezone)->utc(),
            'repeat_rule' => $repeatRule,
            'status' => ScheduledContentStatus::Pending,
            ...$attributes,
        ]);

        return $content;
    }

    public static function timezoneOf(Academy $academy): string
    {
        $timezone = $academy->getAttribute('timezone');

        return is_string($timezone) && $timezone !== ''
            ? $timezone
            : (string) config('app.timezone', 'UTC');
    }

    /** The moment as the academy's staff wrote it. */
    public function localScheduledAt(): Carbon
    {
        return $this->scheduled_at->copy()->setTimezone($this->timezone);
    }

    public function isRecurring(): bool
    {
        return RepeatRule::isRecurring($this->repeat_rule);
    }

    /**
     * Close out a run: reschedule a recurring row to its next local occurrence,
     * or finish a one-shot.
     */
    public function completeRun(?Carbon $now = null): void
    {
        $now ??= now();
        $next = RepeatRule::nextAfterNow($this->repeat_rule, $this->scheduled_at, $this->timezone, $now);

        $this->forceFill([
            'last_run_at' => $now,
            'run_count' => (int) $this->run_count + 1,
            'error' => null,
            ...$next instanceof Carbon
                ? ['scheduled_at' => $next, 'status' => ScheduledContentStatus::Pending]
                : ['executed_at' => $now, 'status' => ScheduledContentStatus::Done],
        ])->save();
    }

    public function failRun(string $error, ?Carbon $now = null): void
    {
        $now ??= now();

        $this->forceFill([
            'status' => ScheduledContentStatus::Failed,
            'last_run_at' => $now,
            'run_count' => (int) $this->run_count + 1,
            'error' => mb_substr($error, 0, 1000),
        ])->save();
    }

    /**
     * Claim the row before doing any work, so two overlapping sweeps cannot both
     * send the same nudge. Returns false when someone else got there first.
     */
    public function claim(): bool
    {
        $claimed = self::query()
            ->withoutGlobalScope('academy')
            ->whereKey($this->getKey())
            ->where('status', ScheduledContentStatus::Pending->value)
            ->update(['status' => ScheduledContentStatus::Running->value]);

        if ($claimed === 0) {
            return false;
        }

        $this->setAttribute('status', ScheduledContentStatus::Running);
        $this->syncOriginalAttribute('status');

        return true;
    }
}

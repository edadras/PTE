<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Models\Exam;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Sets the availability window and attempt allowance for an exam.
 *
 * Times are given in the academy's timezone and stored in UTC — the panel shows
 * a Jalali date to a Tehran administrator, and the row it produces must still
 * mean the same instant to a worker running in UTC.
 *
 * @see docs/05-modules-exams-practice.md §6
 */
final class ScheduleExam
{
    public function __construct(private readonly PublishExam $publish = new PublishExam) {}

    public function handle(
        Exam $exam,
        ?Carbon $opensAt = null,
        ?Carbon $closesAt = null,
        ?int $maxAttempts = null,
        bool $publishNow = false,
    ): Exam {
        $timezone = (string) (TenantContext::get()?->getAttribute('timezone') ?: 'UTC');

        $availability = array_merge($exam->availability ?? [], array_filter([
            'opens_at' => $opensAt?->setTimezone($timezone)->utc()->toDateTimeString(),
            'closes_at' => $closesAt?->setTimezone($timezone)->utc()->toDateTimeString(),
            'max_attempts' => $maxAttempts,
        ], static fn (mixed $value): bool => $value !== null));

        $exam->forceFill(['availability' => $availability])->save();

        // Publishing is separate on purpose: an exam may be scheduled while it is
        // still a draft, and the window alone must not expose it to students.
        if ($publishNow) {
            return $this->publish->handle($exam);
        }

        return $exam;
    }
}

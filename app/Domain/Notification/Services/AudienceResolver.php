<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Identity\Enums\StudentStatus;
use App\Domain\Identity\Models\Student;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns the `audience` JSON on a scheduled row into a student query.
 *
 * Shape (every key optional; an empty audience means "every active student"):
 *
 *   {
 *     "student_ids":     [12, 44],
 *     "class_group_ids": [3],
 *     "status":          "active",
 *     "inactive_days":   7,
 *     "with_telegram":   true
 *   }
 *
 * Blocked Telegram identities are excluded when `with_telegram` is set, because
 * a campaign that keeps messaging people who blocked the bot burns the bot's
 * rate limit on guaranteed failures.
 */
final class AudienceResolver
{
    /**
     * @param  array<string, mixed>  $audience
     * @return Builder<Student>
     */
    public function query(array $audience = []): Builder
    {
        $query = Student::query();

        $status = $audience['status'] ?? StudentStatus::Active->value;

        if ($status !== 'any') {
            $query->where('status', $status instanceof StudentStatus ? $status->value : $status);
        }

        if (is_array($audience['student_ids'] ?? null) && $audience['student_ids'] !== []) {
            $query->whereIn('id', array_map(intval(...), $audience['student_ids']));
        }

        if (is_array($audience['class_group_ids'] ?? null) && $audience['class_group_ids'] !== []) {
            $groupIds = array_map(intval(...), $audience['class_group_ids']);

            $query->whereHas(
                'classGroups',
                fn (Builder $groups): Builder => $groups->whereIn('class_groups.id', $groupIds)
            );
        }

        if (isset($audience['inactive_days'])) {
            $cutoff = now()->subDays(max(1, (int) $audience['inactive_days']));

            $query->where(fn (Builder $inner): Builder => $inner
                ->whereNull('last_active_at')
                ->orWhere('last_active_at', '<', $cutoff));
        }

        if (($audience['with_telegram'] ?? false) === true) {
            $query->whereIn(
                'id',
                fn ($sub) => $sub
                    ->select('student_id')
                    ->from('telegram_identities')
                    ->whereNotNull('student_id')
                    ->where('is_blocked', false)
            );
        }

        return $query->orderBy('id');
    }

    /**
     * @param  array<string, mixed>  $audience
     * @return \Generator<int, Student>
     */
    public function each(array $audience = [], int $chunk = 200): \Generator
    {
        $lastId = 0;
        $base = $this->query($audience);

        do {
            $students = (clone $base)->where('students.id', '>', $lastId)->limit($chunk)->get();

            foreach ($students as $student) {
                $lastId = (int) $student->getKey();

                yield $student;
            }
        } while ($students->count() === $chunk);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Notification\Jobs;

use App\Domain\Assessment\Models\Answer;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Services\AudienceResolver;
use App\Domain\Notification\Services\NotificationDispatcher;
use App\Domain\Reporting\Services\StatCollector;
use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Domain\Tenancy\Data\PlaceholderContext;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;

/**
 * "You have not practised today" — the single highest-retention message the
 * product sends.
 *
 * Two guards keep it from becoming spam: only students who have actually not
 * answered anything today are nudged, and "today" is the academy's calendar day,
 * not the server's. A UTC day boundary would nudge Tehran students at 03:30
 * local and tell someone who practised at 22:00 last night that they have not
 * practised today.
 *
 * @see docs/05-modules-exams-practice.md §6
 */
final class SendDailyPracticeNudge extends TenantAwareJob
{
    public int $tries = 2;

    public int $timeout = 300;

    /**
     * @param  array<string, mixed>  $audience
     */
    public function __construct(
        int $academyId,
        public readonly array $audience = [],
        public readonly ?int $scheduledContentId = null,
    ) {
        parent::__construct($academyId);

        $this->onQueue((string) config('pte.queues.notifications', 'notifications'));
    }

    public function handle(
        AudienceResolver $audiences,
        NotificationDispatcher $dispatcher,
        StatCollector $collector,
    ): int {
        $academy = TenantContext::get();

        if (! $academy instanceof Academy) {
            return 0;
        }

        [$dayStart, $dayEnd] = $collector->window($academy, now($collector->timezoneFor($academy)));

        $practisedToday = Answer::query()
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->distinct()
            ->pluck('student_id')
            ->map(intval(...))
            ->all();

        $practised = array_flip($practisedToday);
        $sent = 0;

        foreach ($audiences->each([...$this->audience, 'with_telegram' => true]) as $student) {
            if (isset($practised[(int) $student->getKey()])) {
                continue;
            }

            $dispatcher->send(
                notifiable: $student,
                templateKey: 'daily_nudge',
                data: ['scheduled_content_id' => $this->scheduledContentId],
                channels: [NotificationChannel::Telegram],
                context: PlaceholderContext::make(['student_name' => $student->fullName()])
                    ->forAcademy($academy)
                    ->withMoment(null, $collector->timezoneFor($academy)),
            );

            $sent++;
        }

        return $sent;
    }
}

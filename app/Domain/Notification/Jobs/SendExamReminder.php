<?php

declare(strict_types=1);

namespace App\Domain\Notification\Jobs;

use App\Domain\Assessment\Models\Exam;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Services\AudienceResolver;
use App\Domain\Notification\Services\NotificationDispatcher;
use App\Domain\Reporting\Services\StatCollector;
use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Domain\Tenancy\Data\PlaceholderContext;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;

/**
 * The 24-hours-before and 1-hour-before reminders from docs/05 §6.
 *
 * The exam time in the message is formatted in the academy's timezone — a
 * student told their exam starts at "05:30" when the academy scheduled 09:00
 * will simply not turn up.
 */
final class SendExamReminder extends TenantAwareJob
{
    public int $tries = 2;

    public int $timeout = 300;

    /**
     * @param  array<string, mixed>  $audience
     */
    public function __construct(
        int $academyId,
        public readonly ?int $examId = null,
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

        $exam = $this->examId === null ? null : Exam::query()->find($this->examId);
        $timezone = $collector->timezoneFor($academy);

        $examStart = $exam?->opensAt();

        $base = PlaceholderContext::make([
            'exam_title' => $exam?->getAttribute('title') ?? '',
            'exam_date' => $examStart?->copy()->setTimezone($timezone)->translatedFormat('Y/m/d') ?? '',
            'exam_time' => $examStart?->copy()->setTimezone($timezone)->format('H:i') ?? '',
        ])->forAcademy($academy)->withMoment(null, $timezone);

        $sent = 0;

        foreach ($audiences->each([...$this->audience, 'with_telegram' => true]) as $student) {
            $dispatcher->send(
                notifiable: $student,
                templateKey: 'exam_reminder',
                data: ['exam_id' => $this->examId, 'scheduled_content_id' => $this->scheduledContentId],
                channels: [NotificationChannel::Telegram],
                context: $base->with(['student_name' => $student->fullName()]),
            );

            $sent++;
        }

        return $sent;
    }
}

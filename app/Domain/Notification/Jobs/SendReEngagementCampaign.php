<?php

declare(strict_types=1);

namespace App\Domain\Notification\Jobs;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Services\AudienceResolver;
use App\Domain\Notification\Services\NotificationDispatcher;
use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Domain\Tenancy\Data\PlaceholderContext;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;

/**
 * Win back students who have not practised for a week (docs/05 §6).
 *
 * Rate-limited to one campaign message per student per cooldown window. Without
 * that, a daily schedule would message the same lapsed student every single day
 * — which is the fastest way to have the bot blocked and lose them for good.
 */
final class SendReEngagementCampaign extends TenantAwareJob
{
    public const TYPE = 're_engagement';

    /** Days before the same student may be re-engaged again. */
    private const COOLDOWN_DAYS = 14;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        int $academyId,
        public readonly int $inactiveDays = 7,
        public readonly ?int $scheduledContentId = null,
    ) {
        parent::__construct($academyId);

        $this->onQueue((string) config('pte.queues.notifications', 'notifications'));
    }

    public function handle(AudienceResolver $audiences, NotificationDispatcher $dispatcher): int
    {
        $academy = TenantContext::get();

        if (! $academy instanceof Academy) {
            return 0;
        }

        $cooldown = now()->subDays(self::COOLDOWN_DAYS);
        $sent = 0;

        $audience = [
            'inactive_days' => max(1, $this->inactiveDays),
            'with_telegram' => true,
        ];

        foreach ($audiences->each($audience) as $student) {
            if ($this->contactedRecently((int) $student->getKey(), $cooldown)) {
                continue;
            }

            $dispatcher->send(
                notifiable: $student,
                templateKey: 'daily_nudge',
                data: [
                    'campaign' => self::TYPE,
                    'inactive_days' => $this->inactiveDays,
                    'scheduled_content_id' => $this->scheduledContentId,
                ],
                channels: [NotificationChannel::Telegram],
                context: PlaceholderContext::make([
                    'student_name' => $student->fullName(),
                    'inactive_days' => $this->inactiveDays,
                ])->forAcademy($academy),
            );

            $sent++;
        }

        return $sent;
    }

    private function contactedRecently(int $studentId, \Illuminate\Support\Carbon $since): bool
    {
        return Notification::query()
            ->where('notifiable_type', \App\Domain\Identity\Models\Student::class)
            ->where('notifiable_id', $studentId)
            ->where('created_at', '>=', $since)
            // A JSON path comparison rather than whereJsonContains: the latter
            // is unsupported on SQLite, which is what the suite runs on.
            ->where('data->campaign', self::TYPE)
            ->exists();
    }
}

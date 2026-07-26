<?php

declare(strict_types=1);

namespace App\Domain\Notification\Jobs;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Services\AudienceResolver;
use App\Domain\Notification\Services\NotificationDispatcher;
use App\Domain\Reporting\Services\StudentProgressReport;
use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;

/**
 * The Friday progress summary from docs/05 §6.
 *
 * The body is composed here from StudentProgressReport rather than resolved
 * from a template, because the interesting part is the student's own numbers.
 * The academy's template still frames it: the composed text is passed as the
 * `body` override, and the greeting/footer around it stays customisable.
 *
 * A student with nothing to report is skipped entirely — a weekly message
 * saying "you practised nothing" every week is how a bot gets muted.
 */
final class SendWeeklyProgressReport extends TenantAwareJob
{
    public int $tries = 2;

    public int $timeout = 600;

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
        StudentProgressReport $progress,
    ): int {
        $academy = TenantContext::get();

        if (! $academy instanceof Academy) {
            return 0;
        }

        $sent = 0;

        foreach ($audiences->each([...$this->audience, 'with_telegram' => true]) as $student) {
            $report = $progress->build($student);

            if ((int) $report['overall']['attempts'] === 0) {
                continue;
            }

            $dispatcher->send(
                notifiable: $student,
                templateKey: 'score_ready',
                data: [
                    'body' => $this->compose($report),
                    'scheduled_content_id' => $this->scheduledContentId,
                ],
                channels: [NotificationChannel::Telegram],
            );

            $sent++;
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function compose(array $report): string
    {
        $lines = [
            __('notifications.weekly.heading'),
            __('notifications.weekly.overall', [
                'percentage' => $report['overall']['percentage'],
                'attempts' => $report['overall']['attempts'],
            ]),
        ];

        if ($report['strengths'] !== []) {
            $lines[] = __('notifications.weekly.strengths', [
                'types' => $this->join($report['strengths']),
            ]);
        }

        if ($report['weaknesses'] !== []) {
            $lines[] = __('notifications.weekly.weaknesses', [
                'types' => $this->join($report['weaknesses']),
            ]);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function join(array $rows): string
    {
        return implode('، ', array_map(
            static fn (array $row): string => sprintf('%s (%s%%)', $row['label'] ?? $row['type'], $row['percentage']),
            $rows
        ));
    }
}

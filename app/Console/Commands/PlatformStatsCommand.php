<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\AI\Models\AiRequest;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Identity\Models\Student;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Telegram\Enums\BotHealthStatus;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Tenancy\Enums\AcademyStatus;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Console\Command;

/**
 * A platform-wide health and business snapshot for the operator on call.
 *
 * Every query here deliberately lifts the tenant scope — this is the one place
 * that is *supposed* to see across academies (docs/02 §7 treats it as a
 * platform action, which is why the command exists rather than a per-tenant
 * dashboard being aggregated by hand).
 *
 * @see docs/10-infrastructure-and-ops.md §8
 */
final class PlatformStatsCommand extends Command
{
    protected $signature = 'platform:stats {--days=7 : Window for the activity figures} {--json}';

    protected $description = 'Platform-wide snapshot: academies, students, activity, AI spend, revenue and bot health.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $academies = Academy::query()->withoutGlobalScopes()->whereNull('deleted_at');

        $stats = [
            'academies' => [
                'total' => (clone $academies)->count(),
                'active' => (clone $academies)->where('status', AcademyStatus::Active->value)->count(),
                'suspended' => (clone $academies)->where('status', AcademyStatus::Suspended->value)->count(),
                'trialing' => (clone $academies)->where('trial_ends_at', '>', now())->count(),
            ],
            'students' => [
                'total' => Student::query()->withoutGlobalScope('academy')->whereNull('deleted_at')->count(),
                'new' => Student::query()->withoutGlobalScope('academy')->where('created_at', '>=', $since)->count(),
                'active' => Student::query()->withoutGlobalScope('academy')->where('last_active_at', '>=', $since)->count(),
            ],
            'activity' => [
                'answers' => Answer::query()->withoutGlobalScope('academy')->where('created_at', '>=', $since)->count(),
                'ai_requests' => AiRequest::query()->withoutGlobalScope('academy')->where('created_at', '>=', $since)->count(),
                'ai_cost_usd' => round((float) AiRequest::query()
                    ->withoutGlobalScope('academy')
                    ->where('created_at', '>=', $since)
                    ->sum('cost_usd'), 4),
            ],
            'revenue' => [
                'window_minor_units' => (int) Payment::query()
                    ->withoutGlobalScope('academy')
                    ->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value])
                    ->where('paid_at', '>=', $since)
                    ->sum('amount'),
            ],
            'bots' => [
                'active' => TelegramBot::query()->withoutGlobalScope('academy')->where('is_active', true)->count(),
                'failing' => TelegramBot::query()
                    ->withoutGlobalScope('academy')
                    ->where('health_status', BotHealthStatus::Failing->value)
                    ->count(),
            ],
            'support' => [
                'open_tickets' => SupportTicket::query()
                    ->withoutGlobalScope('academy')
                    ->whereIn('status', [TicketStatus::Open->value, TicketStatus::Pending->value])
                    ->count(),
            ],
            'window_days' => $days,
            'generated_at' => now()->toIso8601String(),
        ];

        if ($this->option('json') === true) {
            $this->line((string) json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->info(__('reports.console.platform_stats', ['days' => (string) $days]));

        foreach ($stats as $group => $values) {
            if (! is_array($values)) {
                continue;
            }

            $this->newLine();
            $this->line('<fg=cyan>'.$group.'</>');

            foreach ($values as $label => $value) {
                $this->components->twoColumnDetail((string) $label, (string) $value);
            }
        }

        return self::SUCCESS;
    }
}

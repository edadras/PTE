<?php

declare(strict_types=1);

use App\Domain\AI\Jobs\DetectAiCostAnomalies;
use App\Domain\AI\Jobs\ScoringConsistencyAudit;
use App\Domain\Assessment\Jobs\ExpireOverdueExamSessions;
use App\Domain\Commerce\Jobs\CheckSubscriptionExpiry;
use App\Domain\Commerce\Jobs\DetectUnprofitableAcademies;
use App\Domain\Commerce\Jobs\RollUpUsageCounters;
use App\Domain\Commerce\Jobs\SendRenewalReminders;
use App\Domain\Commerce\Jobs\SuspendPastDueAcademies;
use App\Domain\Learning\Jobs\RecalculateDifficultyIndex;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Telegram\Jobs\CheckBotHealth;
use App\Domain\Telegram\Models\TelegramBot;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Two kinds of job live here. Platform jobs sweep every academy themselves and
| take no tenant. Tenant-aware jobs must be fanned out one instance per active
| academy — a single instance would silently only ever see whichever tenant
| happened to be resolved.
|
| @see docs/10-infrastructure-and-ops.md §2
*/

/**
 * @param  callable(Academy): void  $dispatch
 */
$forEachActiveAcademy = static function (callable $dispatch): void {
    Academy::query()
        ->withoutGlobalScopes()
        ->where('status', 'active')
        ->eachById($dispatch, 100);
};

// --- Telegram -------------------------------------------------------------

// Webhooks drift: Telegram silently stops delivering after repeated 5xx, and a
// bot that has quietly stopped answering is the single loudest customer
// complaint. Five minutes is the shortest interval that stays cheap.
Schedule::call(function (): void {
    TelegramBot::query()
        ->withoutGlobalScopes()
        ->where('is_active', true)
        ->eachById(fn (TelegramBot $bot) => CheckBotHealth::dispatch($bot->academy_id, $bot->getKey()), 100);
})->everyFiveMinutes()->name('telegram:health')->withoutOverlapping();

// --- Assessment -----------------------------------------------------------

// Exam timers are authoritative on the server, so an abandoned session has to
// be closed by us rather than by the client that walked away.
Schedule::call(function () use ($forEachActiveAcademy): void {
    $forEachActiveAcademy(fn (Academy $a) => ExpireOverdueExamSessions::dispatch($a->getKey()));
})->everyMinute()->name('exams:expire')->withoutOverlapping();

// --- Learning -------------------------------------------------------------

// The observed difficulty index is what makes adaptive practice meaningful;
// recomputing it weekly keeps it responsive without thrashing the answer table.
Schedule::call(function () use ($forEachActiveAcademy): void {
    $forEachActiveAcademy(fn (Academy $a) => RecalculateDifficultyIndex::dispatch($a->getKey()));
})->weeklyOn(1, '04:00')->name('learning:difficulty')->withoutOverlapping();

// --- AI -------------------------------------------------------------------

// Model drift and prompt edits both show up as scores moving without anything
// else changing. Re-scoring a sample weekly is the only way we notice.
Schedule::call(function () use ($forEachActiveAcademy): void {
    $forEachActiveAcademy(fn (Academy $a) => ScoringConsistencyAudit::dispatch($a->getKey()));
})->weeklyOn(0, '05:00')->name('ai:consistency')->withoutOverlapping();

Schedule::job(new DetectAiCostAnomalies)->dailyAt('03:00')->name('ai:cost-anomalies');

// --- Commerce -------------------------------------------------------------

Schedule::job(new RollUpUsageCounters)->hourly()->name('billing:usage-rollup');
Schedule::job(new CheckSubscriptionExpiry)->dailyAt('01:00')->name('billing:expiry');
Schedule::job(new SuspendPastDueAcademies)->dailyAt('02:00')->name('billing:suspend');
Schedule::job(new DetectUnprofitableAcademies)->dailyAt('03:30')->name('billing:profitability');
Schedule::job(new SendRenewalReminders)->dailyAt('08:00')->name('billing:reminders');

// --- Housekeeping ---------------------------------------------------------

Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('queue:prune-batches --hours=48')->daily();
Schedule::command('auth:clear-resets')->daily();

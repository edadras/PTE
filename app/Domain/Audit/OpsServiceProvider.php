<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Console\Commands\AcademyCloneCommand;
use App\Console\Commands\AcademyCreateCommand;
use App\Console\Commands\AcademyExportCommand;
use App\Console\Commands\AcademyListCommand;
use App\Console\Commands\AcademyResumeCommand;
use App\Console\Commands\AcademySuspendCommand;
use App\Console\Commands\AiCostReportCommand;
use App\Console\Commands\AiRescoreCommand;
use App\Console\Commands\AiTestPromptCommand;
use App\Console\Commands\CleanupExpiredDataCommand;
use App\Console\Commands\PlatformStatsCommand;
use App\Console\Commands\TelegramHealthCheckCommand;
use App\Console\Commands\TelegramRegisterWebhookCommand;
use App\Console\Commands\TelegramResetWebhookCommand;
use App\Console\Commands\TenantRunCommand;
use App\Domain\Assessment\Events\AnswerScoreOverridden;
use App\Domain\Assessment\Events\ExamPublished;
use App\Domain\Audit\Listeners\RecordBotConnection;
use App\Domain\Audit\Listeners\RecordDataExport;
use App\Domain\Audit\Listeners\RecordExamPublication;
use App\Domain\Audit\Listeners\RecordPaymentActivity;
use App\Domain\Audit\Listeners\RecordScoreOverride;
use App\Domain\Audit\Listeners\RecordSubscriptionChange;
use App\Domain\Audit\Listeners\RecordTicketActivity;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Commerce\Events\PaymentRecorded;
use App\Domain\Commerce\Events\SubscriptionCanceled;
use App\Domain\Commerce\Events\SubscriptionStatusChanged;
use App\Domain\Notification\Contracts\SmsDriver;
use App\Domain\Notification\Services\NullSmsDriver;
use App\Domain\Reporting\Events\ReportExported;
use App\Domain\Telegram\Events\BotConnected;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Support, Reporting, Audit and Notification layer.
 *
 * One provider rather than four because everything here is the same seam: the
 * operational surface of the product. The listeners it registers are the
 * mandatory-audit hooks from docs/02 §7, and the commands are the ops CLI from
 * docs/10 §8.
 *
 * Register in bootstrap/providers.php.
 */
final class OpsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton so a console command or job can pin an actor once with
        // actingAs() and have every subsequent record attribute correctly.
        $this->app->singleton(AuditRecorder::class);

        // The platform ships no SMS gateway; a deployment overrides this
        // binding with a real driver. See SmsDriver for the config shape.
        $this->app->bindIf(SmsDriver::class, NullSmsDriver::class);
    }

    public function boot(): void
    {
        $this->registerAuditListeners();
        $this->registerCommands();
    }

    /**
     * @see docs/02-roles-and-rbac.md §7
     */
    private function registerAuditListeners(): void
    {
        Event::listen(AnswerScoreOverridden::class, [RecordScoreOverride::class, 'handle']);
        Event::listen(SubscriptionStatusChanged::class, [RecordSubscriptionChange::class, 'handle']);
        Event::listen(SubscriptionCanceled::class, [RecordSubscriptionChange::class, 'handleCanceled']);
        Event::listen(PaymentRecorded::class, [RecordPaymentActivity::class, 'handle']);
        Event::listen(BotConnected::class, [RecordBotConnection::class, 'handle']);
        Event::listen(ExamPublished::class, [RecordExamPublication::class, 'handle']);
        Event::listen(ReportExported::class, [RecordDataExport::class, 'handle']);

        Event::subscribe(RecordTicketActivity::class);
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            AcademyCreateCommand::class,
            AcademySuspendCommand::class,
            AcademyResumeCommand::class,
            AcademyExportCommand::class,
            AcademyCloneCommand::class,
            AcademyListCommand::class,
            TelegramRegisterWebhookCommand::class,
            TelegramHealthCheckCommand::class,
            TelegramResetWebhookCommand::class,
            AiTestPromptCommand::class,
            AiCostReportCommand::class,
            AiRescoreCommand::class,
            TenantRunCommand::class,
            PlatformStatsCommand::class,
            CleanupExpiredDataCommand::class,
        ]);
    }
}

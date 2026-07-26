<?php

declare(strict_types=1);

namespace App\Domain\Audit;

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
 * operational surface of the product. What it registers is the mandatory-audit
 * hooks from docs/02 §7 plus two container bindings.
 *
 * The ops CLI (docs/10 §8) is deliberately absent: Application::configure()
 * already calls withCommands(), which discovers everything under
 * app/Console/Commands, so listing them here would only be a second place to
 * forget to update.
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
}

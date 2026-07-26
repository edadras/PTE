<?php

declare(strict_types=1);

namespace App\Domain\Integration\Providers;

use App\Domain\Identity\Models\Student;
use App\Domain\Integration\Enums\WebhookEvent;
use App\Domain\Integration\Listeners\EmitDomainWebhooks;
use App\Domain\Integration\Services\WebhookEmitter;
use App\Domain\Integration\Support\ApiRateLimiters;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the outbound-integration context.
 *
 * Register in bootstrap/providers.php.
 *
 * @see docs/08-api-and-integrations.md §5
 */
final class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WebhookEmitter::class);
    }

    public function boot(): void
    {
        ApiRateLimiters::register();

        Event::subscribe(EmitDomainWebhooks::class);

        $this->observeStudents();
    }

    /**
     * `student.created` / `student.updated` are emitted from model events rather
     * than a domain event, because a student can be created by the bot, the
     * panel, a CSV import or the API and every one of those must reach the CRM.
     */
    private function observeStudents(): void
    {
        Student::created(function (Student $student): void {
            $this->emitter()->emit(
                WebhookEvent::StudentCreated,
                $this->studentPayload($student),
                (int) $student->academy_id,
            );
        });

        Student::updated(function (Student $student): void {
            $this->emitter()->emit(
                WebhookEvent::StudentUpdated,
                $this->studentPayload($student) + ['changed' => array_keys($student->getChanges())],
                (int) $student->academy_id,
            );
        });
    }

    /**
     * Contact details are deliberately absent — a webhook body travels over the
     * academy's own TLS to a third-party system (docs/12 §4).
     *
     * @return array<string, mixed>
     */
    private function studentPayload(Student $student): array
    {
        return [
            'id' => (int) $student->getKey(),
            'student_code' => $student->student_code,
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'status' => $student->status->value,
            'source' => $student->source->value,
            'level' => $student->level,
        ];
    }

    private function emitter(): WebhookEmitter
    {
        return $this->app->make(WebhookEmitter::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Jobs\ScoreAnswerWithAi;
use App\Domain\AI\Services\AiGateway;
use App\Domain\Assessment\Enums\ScoringStatus;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-run AI scoring for one answer.
 *
 * Runbook material: an answer parked in `manual_review` after a provider outage
 * needs a second attempt once the provider is back, and a teacher should not
 * have to grade it by hand because of our downtime.
 *
 * A teacher's override is never overwritten. That number is the one a student
 * was told and possibly disputed; replacing it silently with a fresh model
 * opinion would destroy the audit trail the override exists to create.
 *
 * @see docs/10-infrastructure-and-ops.md §8
 */
final class AiRescoreCommand extends Command
{
    protected $signature = 'ai:rescore
        {answer : Answer id}
        {--sync : Score inline instead of queueing}
        {--force : Rescore even if a teacher has overridden the score}';

    protected $description = 'Re-run AI scoring for a single answer.';

    public function handle(AiGateway $gateway): int
    {
        $answerId = (int) $this->argument('answer');

        // Unscoped: the operator has an id from a log line, not a tenant.
        $answer = Answer::query()->withoutGlobalScope('academy')->find($answerId);

        if (! $answer instanceof Answer) {
            $this->components->error(__('reports.console.answer_not_found', ['id' => (string) $answerId]));

            return self::FAILURE;
        }

        $academy = Academy::query()->withoutGlobalScopes()->find($answer->academy_id);

        if (! $academy instanceof Academy) {
            $this->components->error(__('reports.console.academy_not_found', ['reference' => (string) $answer->academy_id]));

            return self::FAILURE;
        }

        if ($answer->graded_manually && $this->option('force') !== true) {
            $this->components->error(__('reports.console.answer_overridden'));

            return self::INVALID;
        }

        return TenantContext::runFor($academy, function () use ($answer, $academy, $gateway): int {
            $task = $gateway->taskFor($answer);

            if (! $task instanceof AiTaskKey) {
                $this->components->error(__('reports.console.answer_not_ai_scored'));

                return self::INVALID;
            }

            $before = $answer->score;

            // Reset so the job does not short-circuit on an already-final status.
            $answer->forceFill(['scoring_status' => ScoringStatus::Pending])->save();

            if ($this->option('sync') !== true) {
                ScoreAnswerWithAi::dispatch((int) $academy->getKey(), (int) $answer->getKey());

                $this->components->info(__('reports.console.rescore_queued', ['id' => (string) $answer->getKey()]));

                return self::SUCCESS;
            }

            try {
                $gateway->scoreAnswer($answer);
            } catch (Throwable $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $answer->refresh();

            $this->components->twoColumnDetail(__('reports.console.score_before'), (string) ($before ?? '—'));
            $this->components->twoColumnDetail(__('reports.console.score_after'), (string) ($answer->score ?? '—'));
            $this->components->twoColumnDetail(__('reports.console.scoring_status'), $answer->scoring_status->value);

            return self::SUCCESS;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\AI\Jobs;

use App\Domain\AI\Events\AnswerScoringFailed;
use App\Domain\AI\Services\AiGateway;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Shared\Jobs\TenantAwareJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The orchestration entry point for AI scoring.
 *
 * The job itself stays thin — quota, prompt, provider, validation, rubric and
 * persistence all live in AiGateway, so the Telegram bot, the API and the panel
 * get identical behaviour (CONVENTIONS §6). What the job owns is the failure
 * contract: whatever happens, the student is never shown a technical error and
 * the answer never disappears.
 */
final class ScoreAnswerWithAi extends TenantAwareJob
{
    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(int $academyId, public readonly int $answerId)
    {
        parent::__construct($academyId);

        $this->onQueue((string) config('pte.queues.ai_scoring', 'ai-scoring'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(AiGateway $gateway): void
    {
        $answer = Answer::query()->find($this->answerId);

        if ($answer === null) {
            return;
        }

        // Re-queued work must not overwrite a score a teacher has since fixed.
        if (in_array((string) $answer->scoring_status, [AiGateway::STATUS_SCORED, 'overridden'], true)) {
            return;
        }

        $gateway->scoreAnswer($answer);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ScoreAnswerWithAi exhausted its attempts', [
            'answer_id' => $this->answerId,
            'academy_id' => $this->academyId,
            'exception' => $exception?->getMessage(),
        ]);

        $answer = Answer::query()->find($this->answerId);

        if ($answer !== null) {
            $answer->forceFill(['scoring_status' => AiGateway::STATUS_MANUAL_REVIEW])->save();
        }

        AnswerScoringFailed::dispatch(
            $this->academyId,
            $this->answerId,
            'scoring',
            $exception?->getMessage() ?? 'unknown',
        );
    }
}

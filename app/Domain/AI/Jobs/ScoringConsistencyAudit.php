<?php

declare(strict_types=1);

namespace App\Domain\AI\Jobs;

use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Events\ScoringDriftDetected;
use App\Domain\AI\Models\AiRubric;
use App\Domain\AI\Services\AiGateway;
use App\Domain\AI\Services\RubricEngine;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Shared\Jobs\TenantAwareJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Weekly fairness check: re-score a random sample of last week's answers and
 * compare. A mean deviation above the threshold means the model drifted or a
 * prompt changed underneath us — either way, scores students already received
 * are no longer reproducible (docs/06 §9).
 *
 * This deliberately spends money. Twenty calls a week per academy is a rounding
 * error next to discovering the drift from a complaint.
 */
final class ScoringConsistencyAudit extends TenantAwareJob
{
    public const DEFAULT_SAMPLE_SIZE = 20;

    public const DEFAULT_THRESHOLD = 5.0;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        int $academyId,
        public readonly int $sampleSize = self::DEFAULT_SAMPLE_SIZE,
        public readonly float $threshold = self::DEFAULT_THRESHOLD,
    ) {
        parent::__construct($academyId);

        $this->onQueue((string) config('pte.queues.maintenance', 'maintenance'));
    }

    public function handle(AiGateway $gateway, RubricEngine $rubrics): void
    {
        $answers = Answer::query()
            ->whereNotNull('score')
            ->whereNotNull('ai_request_id')
            ->where('created_at', '>=', now()->subWeek())
            ->inRandomOrder()
            ->limit($this->sampleSize)
            ->get();

        if ($answers->isEmpty()) {
            return;
        }

        /** @var array<string, array<int, array{answer_id: int, original: float, rescored: float, delta: float}>> $byTask */
        $byTask = [];

        foreach ($answers as $answer) {
            $task = $gateway->taskFor($answer);

            if (! $task instanceof AiTaskKey) {
                continue;
            }

            $rubric = $rubrics->activeFor($task, $this->academyId);

            if (! $rubric instanceof AiRubric) {
                continue;
            }

            try {
                $response = $gateway->run($task, $gateway->variablesFor($answer, $task, $rubric), $answer);
            } catch (Throwable $e) {
                Log::info('Consistency audit skipped an answer', [
                    'answer_id' => $answer->getKey(),
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            $rescored = $rubrics->apply($rubric, $response->scores())->scaledScore;
            $original = (float) $answer->score;

            $byTask[$task->value][] = [
                'answer_id' => (int) $answer->getKey(),
                'original' => $original,
                'rescored' => $rescored,
                'delta' => round($rescored - $original, 2),
            ];
        }

        foreach ($byTask as $taskKey => $samples) {
            $deviation = $this->meanAbsoluteDeviation($samples);

            if ($deviation <= $this->threshold) {
                continue;
            }

            ScoringDriftDetected::dispatch(
                $this->academyId,
                $taskKey,
                $deviation,
                $this->threshold,
                count($samples),
                $samples,
            );
        }
    }

    /**
     * @param  array<int, array{answer_id: int, original: float, rescored: float, delta: float}>  $samples
     */
    private function meanAbsoluteDeviation(array $samples): float
    {
        if ($samples === []) {
            return 0.0;
        }

        $total = array_sum(array_map(static fn (array $s): float => abs($s['delta']), $samples));

        return round($total / count($samples), 3);
    }
}

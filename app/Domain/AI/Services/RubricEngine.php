<?php

declare(strict_types=1);

namespace App\Domain\AI\Services;

use App\Domain\AI\Data\RubricCriterion;
use App\Domain\AI\Data\ScoreBreakdown;
use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Exceptions\InvalidRubricException;
use App\Domain\AI\Models\AiRubric;

/**
 * Turns the model's raw 0–100 per criterion into a score on the academy's scale.
 *
 * The arithmetic lives here and nowhere else (ADR-007). Language models are
 * unreliable at exact arithmetic, and a weighting done in the prompt cannot be
 * re-run, audited, or corrected after the fact — this one can, for every
 * historical answer, without spending a token.
 *
 * @see docs/06-ai-layer.md §4
 */
final class RubricEngine
{
    /**
     * @param  array<string, mixed>  $rawScores  criterion key => 0..100 from the model
     */
    public function apply(AiRubric $rubric, array $rawScores): ScoreBreakdown
    {
        $criteria = $this->criteria($rubric);

        $this->assertWeightsSumTo100($criteria);

        $raw = [];
        $weighted = [];
        $weights = [];
        $missing = [];
        $total = 0.0;
        $coveredWeight = 0;

        foreach ($criteria as $criterion) {
            $weights[$criterion->key] = $criterion->weight;

            if (! array_key_exists($criterion->key, $rawScores) || ! is_numeric($rawScores[$criterion->key])) {
                $missing[] = $criterion->key;

                continue;
            }

            $value = $this->clamp((float) $rawScores[$criterion->key]);
            $contribution = $value * ($criterion->weight / 100);

            $raw[$criterion->key] = $value;
            $weighted[$criterion->key] = round($contribution, 4);
            $total += $contribution;
            $coveredWeight += $criterion->weight;
        }

        // A partially answered rubric is re-normalised over the criteria we did
        // get, rather than silently scoring the missing ones as zero — that
        // would fail a student for a model's omission. The gap is recorded so
        // the answer can still be flagged for review.
        if ($missing !== [] && $coveredWeight > 0) {
            $total = $total * (100 / $coveredWeight);
        }

        $scaled = $this->scale($rubric, $total);

        return new ScoreBreakdown(
            rawScores: $raw,
            weightedScores: $weighted,
            weights: $weights,
            rawTotal: round($total, 4),
            scaledScore: $scaled,
            scaleMin: $rubric->scale_min,
            scaleMax: $rubric->scale_max,
            rounding: (string) $rubric->rounding,
            missingCriteria: $missing,
        );
    }

    /**
     * @return array<int, RubricCriterion>
     */
    public function criteria(AiRubric $rubric): array
    {
        return $rubric->criterionObjects();
    }

    /**
     * @param  array<int, RubricCriterion>  $criteria
     *
     * @throws InvalidRubricException
     */
    public function assertWeightsSumTo100(array $criteria): void
    {
        if ($criteria === []) {
            throw InvalidRubricException::emptyCriteria();
        }

        $total = array_sum(array_map(static fn (RubricCriterion $c): int => $c->weight, $criteria));

        if ($total !== 100) {
            throw InvalidRubricException::weightsDoNotSumTo100($total);
        }
    }

    /**
     * Validation entry point for the panel: raw editor rows in, typed criteria
     * out, exception if the weights do not add up.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, RubricCriterion>
     */
    public function validateCriteria(array $rows): array
    {
        $criteria = array_map(
            static fn (array $row): RubricCriterion => RubricCriterion::fromArray($row),
            array_values($rows),
        );

        $seen = [];

        foreach ($criteria as $criterion) {
            if (isset($seen[$criterion->key])) {
                throw InvalidRubricException::duplicateCriterion($criterion->key);
            }

            $seen[$criterion->key] = true;
        }

        $this->assertWeightsSumTo100($criteria);

        return $criteria;
    }

    public function activeFor(AiTaskKey $task, ?int $academyId = null): ?AiRubric
    {
        return AiRubric::resolveFor($task, $academyId);
    }

    /**
     * Human-readable weights for the {{rubric_weights}} prompt variable. The
     * model is told the weights so its narrative feedback emphasises the right
     * things — it is never asked to apply them.
     *
     * @return array<string, int>
     */
    public function weightsForPrompt(AiRubric $rubric): array
    {
        return $rubric->weightMap();
    }

    /**
     * Descriptive guidance block appended to the user message so each criterion
     * is scored against the academy's own definition.
     */
    public function guidanceForPrompt(AiRubric $rubric): string
    {
        $lines = [];

        foreach ($this->criteria($rubric) as $criterion) {
            $lines[] = sprintf(
                '- %s (%s, weight %d%%): %s',
                $criterion->key,
                $criterion->label,
                $criterion->weight,
                $criterion->guidance !== '' ? $criterion->guidance : 'Score 0-100.',
            );
        }

        return implode("\n", $lines);
    }

    /** Re-derive a stored breakdown under a different rubric — no AI call needed. */
    public function rescore(AiRubric $rubric, ScoreBreakdown $previous): ScoreBreakdown
    {
        return $this->apply($rubric, $previous->rawScores);
    }

    private function scale(AiRubric $rubric, float $percentage): float
    {
        $span = $rubric->scale_max - $rubric->scale_min;
        $value = $rubric->scale_min + ($percentage / 100) * $span;

        return $rubric->round(max((float) $rubric->scale_min, min((float) $rubric->scale_max, $value)));
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }
}

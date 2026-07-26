<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Assessment\Models\Answer;
use App\Domain\Learning\Enums\Difficulty;
use App\Domain\Learning\Models\Question;

/**
 * Turns real answers into a measured difficulty.
 *
 *     difficulty_index = 1 − (average score of the last 50 answers / max score)
 *
 * The author's `difficulty` label is a guess; this number is evidence, and it
 * is what the adaptive practice engine actually targets.
 *
 * @see docs/05-modules-exams-practice.md §3
 */
final class DifficultyCalculator
{
    public const SAMPLE_SIZE = 50;

    /** Below this many answers the sample is noise, so the label is trusted instead. */
    public const MIN_SAMPLE = 5;

    /**
     * Compute and persist the index, and refresh the denormalised avg_score.
     */
    public function recalculate(Question $question): float
    {
        $sample = $this->sampleFor($question);

        if ($sample === []) {
            return (float) $question->difficulty_index;
        }

        $index = $this->indexFromSample($sample, $question->difficulty);
        $avgScore = array_sum(array_column($sample, 'score')) / count($sample);

        $question->forceFill([
            'difficulty_index' => round($index, 2),
            'avg_score' => round($avgScore, 2),
        ])->save();

        return round($index, 2);
    }

    /** The same computation without touching the database. */
    public function preview(Question $question): float
    {
        $sample = $this->sampleFor($question);

        return $sample === []
            ? (float) $question->difficulty_index
            : round($this->indexFromSample($sample, $question->difficulty), 2);
    }

    /**
     * @param  array<int, array{score: float, max_score: float}>  $sample
     */
    private function indexFromSample(array $sample, Difficulty $declared): float
    {
        $ratios = [];

        foreach ($sample as $answer) {
            if ($answer['max_score'] <= 0.0) {
                continue;
            }

            $ratios[] = min(1.0, max(0.0, $answer['score'] / $answer['max_score']));
        }

        if ($ratios === []) {
            return $declared->defaultIndex();
        }

        $measured = 1.0 - (array_sum($ratios) / count($ratios));

        if (count($ratios) >= self::MIN_SAMPLE) {
            return $this->clamp($measured);
        }

        // Small samples are blended with the declared band so one unlucky
        // student cannot mark an easy question as hard.
        $weight = count($ratios) / self::MIN_SAMPLE;

        return $this->clamp(($measured * $weight) + ($declared->defaultIndex() * (1 - $weight)));
    }

    /**
     * @return array<int, array{score: float, max_score: float}>
     */
    private function sampleFor(Question $question): array
    {
        return Answer::query()
            ->where('question_id', $question->getKey())
            ->whereNotNull('score')
            ->where('max_score', '>', 0)
            ->orderByDesc('id')
            ->limit(self::SAMPLE_SIZE)
            ->get(['score', 'max_score'])
            ->map(static fn (object $answer): array => [
                'score' => (float) $answer->score,
                'max_score' => (float) $answer->max_score,
            ])
            ->all();
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}

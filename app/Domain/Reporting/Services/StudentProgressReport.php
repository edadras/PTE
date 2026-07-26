<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Assessment\Models\Answer;
use App\Domain\Identity\Models\Student;
use App\Domain\Identity\Models\StudentProgress;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Enums\QuestionType;
use Illuminate\Support\Carbon;

/**
 * Strengths and weaknesses per question type, for the weekly report and the
 * bot's "where am I losing marks" answer.
 *
 * Reads the materialised `student_progress` table by default. Falling back to
 * `answers` is offered explicitly rather than silently, because on a busy
 * academy that fallback is a table scan and the caller should have to ask for
 * it (docs/07 §11).
 */
final class StudentProgressReport
{
    /** Percentages above/below which a type is called out. */
    private const STRENGTH_THRESHOLD = 75.0;

    private const WEAKNESS_THRESHOLD = 55.0;

    /** A type with fewer attempts than this is not evidence of anything. */
    private const MIN_ATTEMPTS = 3;

    private const HIGHLIGHT_LIMIT = 3;

    /**
     * @return array<string, mixed>
     */
    public function build(Student|int $student, bool $fromAnswers = false): array
    {
        $studentId = $student instanceof Student ? (int) $student->getKey() : $student;

        $byType = $fromAnswers ? $this->fromAnswers($studentId) : $this->fromProgress($studentId);

        $overall = $this->overall($byType);

        return [
            'student_id' => $studentId,
            'source' => $fromAnswers ? 'answers' : 'student_progress',
            'overall' => $overall,
            'by_module' => $this->byModule($byType),
            'by_type' => array_values($byType),
            'strengths' => $this->highlight($byType, true),
            'weaknesses' => $this->highlight($byType, false),
            'untouched' => $this->untouched($byType),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Types the student has practised least — what a nudge should suggest next.
     *
     * @return array<int, string>
     */
    public function suggestedNextTypes(Student|int $student, int $limit = 3): array
    {
        $report = $this->build($student);

        $weak = array_map(
            static fn (array $row): string => (string) $row['type'],
            $report['weaknesses'],
        );

        return array_values(array_slice([...$weak, ...$report['untouched']], 0, $limit));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fromProgress(int $studentId): array
    {
        $rows = StudentProgress::query()
            ->where('student_id', $studentId)
            ->get(['module_key', 'question_type', 'attempts', 'avg_score', 'best_score', 'last_score', 'last_practiced_at']);

        $byType = [];

        foreach ($rows as $row) {
            $type = QuestionType::tryFrom((string) $row->question_type);

            if (! $type instanceof QuestionType) {
                continue;
            }

            $byType[$type->value] = [
                'type' => $type->value,
                'label' => $type->label(),
                'module_key' => $type->module()->value,
                'attempts' => (int) $row->attempts,
                'percentage' => round((float) ($row->avg_score ?? 0.0), 2),
                'best' => $row->best_score === null ? null : round((float) $row->best_score, 2),
                'last' => $row->last_score === null ? null : round((float) $row->last_score, 2),
                'last_practiced_at' => $row->last_practiced_at instanceof Carbon
                    ? $row->last_practiced_at->toIso8601String()
                    : null,
            ];
        }

        return $byType;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fromAnswers(int $studentId): array
    {
        $answers = Answer::query()
            ->where('student_id', $studentId)
            ->whereNotNull('score')
            ->orderBy('id')
            ->get(['answer_data', 'score', 'max_score', 'created_at']);

        $byType = [];

        foreach ($answers as $answer) {
            $raw = $answer->payloadValue('question_type');
            $type = is_string($raw) ? QuestionType::tryFrom($raw) : null;

            if (! $type instanceof QuestionType) {
                continue;
            }

            $bucket = $byType[$type->value] ?? [
                'type' => $type->value,
                'label' => $type->label(),
                'module_key' => $type->module()->value,
                'attempts' => 0,
                'score_sum' => 0.0,
                'max_sum' => 0.0,
                'last' => null,
                'best' => null,
                'last_practiced_at' => null,
            ];

            $score = (float) ($answer->score ?? 0.0);
            $max = (float) ($answer->max_score ?? 0.0);
            $percentage = $max > 0.0 ? round($score / $max * 100, 2) : 0.0;

            $bucket['attempts']++;
            $bucket['score_sum'] += $score;
            $bucket['max_sum'] += $max;
            $bucket['last'] = $percentage;
            $bucket['best'] = max((float) ($bucket['best'] ?? 0.0), $percentage);
            $bucket['last_practiced_at'] = $answer->getAttribute('created_at')?->toIso8601String();

            $byType[$type->value] = $bucket;
        }

        foreach ($byType as $key => $bucket) {
            $byType[$key]['percentage'] = $bucket['max_sum'] > 0.0
                ? round($bucket['score_sum'] / $bucket['max_sum'] * 100, 2)
                : 0.0;

            unset($byType[$key]['score_sum'], $byType[$key]['max_sum']);
        }

        return $byType;
    }

    /**
     * @param  array<string, array<string, mixed>>  $byType
     * @return array<string, mixed>
     */
    private function overall(array $byType): array
    {
        $attempts = array_sum(array_map(static fn (array $r): int => (int) $r['attempts'], $byType));

        if ($attempts === 0) {
            return ['attempts' => 0, 'percentage' => 0.0, 'types_practised' => 0];
        }

        // Weighted by attempts: a type tried once must not swing the average as
        // hard as one tried fifty times.
        $weighted = array_sum(array_map(
            static fn (array $r): float => (float) $r['percentage'] * (int) $r['attempts'],
            $byType
        ));

        return [
            'attempts' => $attempts,
            'percentage' => round($weighted / $attempts, 2),
            'types_practised' => count($byType),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $byType
     * @return array<int, array<string, mixed>>
     */
    private function byModule(array $byType): array
    {
        $modules = [];

        foreach ($byType as $row) {
            $key = (string) $row['module_key'];
            $bucket = $modules[$key] ?? ['module_key' => $key, 'attempts' => 0, 'weighted' => 0.0, 'types' => 0];

            $bucket['attempts'] += (int) $row['attempts'];
            $bucket['weighted'] += (float) $row['percentage'] * (int) $row['attempts'];
            $bucket['types']++;

            $modules[$key] = $bucket;
        }

        return array_values(array_map(static function (array $bucket): array {
            $module = ModuleKey::tryFrom((string) $bucket['module_key']);

            return [
                'module_key' => $bucket['module_key'],
                'label' => $module?->label() ?? $bucket['module_key'],
                'attempts' => $bucket['attempts'],
                'types' => $bucket['types'],
                'percentage' => $bucket['attempts'] > 0
                    ? round($bucket['weighted'] / $bucket['attempts'], 2)
                    : 0.0,
            ];
        }, $modules));
    }

    /**
     * @param  array<string, array<string, mixed>>  $byType
     * @return array<int, array<string, mixed>>
     */
    private function highlight(array $byType, bool $strengths): array
    {
        $threshold = $strengths ? self::STRENGTH_THRESHOLD : self::WEAKNESS_THRESHOLD;

        $rows = array_values(array_filter(
            $byType,
            static fn (array $row): bool => (int) $row['attempts'] >= self::MIN_ATTEMPTS
                && ($strengths
                    ? (float) $row['percentage'] >= $threshold
                    : (float) $row['percentage'] <= $threshold)
        ));

        usort(
            $rows,
            static fn (array $a, array $b): int => $strengths
                ? $b['percentage'] <=> $a['percentage']
                : $a['percentage'] <=> $b['percentage']
        );

        return array_slice($rows, 0, self::HIGHLIGHT_LIMIT);
    }

    /**
     * @param  array<string, array<string, mixed>>  $byType
     * @return array<int, string>
     */
    private function untouched(array $byType): array
    {
        return array_values(array_map(
            static fn (QuestionType $type): string => $type->value,
            array_filter(
                QuestionType::cases(),
                static fn (QuestionType $type): bool => ! array_key_exists($type->value, $byType)
            )
        ));
    }
}

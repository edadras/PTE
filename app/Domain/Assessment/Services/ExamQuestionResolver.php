<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Services;

use App\Domain\Assessment\Enums\SelectionMode;
use App\Domain\Assessment\Models\ExamQuestion;
use App\Domain\Assessment\Models\ExamSection;
use App\Domain\Identity\Models\Student;
use App\Domain\Learning\Services\QuestionSelector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Decides which questions a given student actually sits, for one section.
 *
 * @see docs/05-modules-exams-practice.md §5
 */
final class ExamQuestionResolver
{
    public function __construct(private readonly QuestionSelector $selector) {}

    /**
     * @return Collection<int, array{question: Model, score: float, sort_order: int}>
     */
    public function resolve(ExamSection $section, Student $student, bool $shuffle = false): Collection
    {
        $questions = match ($section->selection_mode) {
            SelectionMode::Manual => $this->manual($section),
            SelectionMode::Random => $this->random($section, $student),
            SelectionMode::Pool => $this->pool($section, $student),
        };

        if ($shuffle && $section->selection_mode !== SelectionMode::Pool) {
            // Pool draws are already student-specific; shuffling them again buys
            // nothing and would only make support calls harder to reproduce.
            $questions = $this->deterministicOrder($questions, $this->seed($section, $student));
        }

        $perQuestionScore = $this->perQuestionScore($section, $questions->count());

        return $questions->values()->map(fn (Model $question, int $index): array => [
            'question' => $question,
            'score' => $this->scoreFor($section, (int) $question->getKey(), $perQuestionScore),
            'sort_order' => $index,
        ]);
    }

    /**
     * @return Collection<int, Model>
     */
    private function manual(ExamSection $section): Collection
    {
        return $section->questions()
            ->with('question')
            ->get()
            ->map(static fn (ExamQuestion $row): ?Model => $row->question)
            ->filter()
            ->values();
    }

    /**
     * Filtered draw from the bank at start time.
     *
     * @return Collection<int, Model>
     */
    private function random(ExamSection $section, Student $student): Collection
    {
        $count = max(0, (int) $section->config('count', 0));

        if ($count === 0) {
            return collect();
        }

        $types = $section->configuredTypes();

        $options = array_filter([
            'difficulty' => $section->config('difficulty'),
            'bank_id' => $section->config('bank_id'),
            'mode' => 'random',
            'exam_section_id' => $section->getKey(),
        ], static fn (mixed $value): bool => $value !== null);

        if ($types === []) {
            return $this->selector->select($section->module_key, $student, $count, $options)->values();
        }

        // Spread the requested count over the configured types, remainder first,
        // so "5 questions across 2 types" gives 3 + 2 rather than silently 5 + 0.
        $picked = collect();
        $remaining = $count;

        foreach ($types as $index => $type) {
            $share = (int) ceil($remaining / (count($types) - $index));
            $remaining -= $share;

            if ($share > 0) {
                $picked = $picked->merge($this->selector->select($type, $student, $share, $options));
            }
        }

        return $picked->values();
    }

    /**
     * Anti-cheating draw: a large candidate pool, a per-student subset.
     *
     * The subset is derived from a stable seed rather than rand(), so a student
     * who reconnects mid-exam is handed the same questions back.
     *
     * @return Collection<int, Model>
     */
    private function pool(ExamSection $section, Student $student): Collection
    {
        $candidates = $this->manual($section);
        $take = max(0, (int) $section->config('take', $candidates->count()));

        if ($take === 0 || $take >= $candidates->count()) {
            return $candidates;
        }

        return $this->deterministicOrder($candidates, $this->seed($section, $student))->take($take)->values();
    }

    /**
     * @param  Collection<int, Model>  $questions
     * @return Collection<int, Model>
     */
    private function deterministicOrder(Collection $questions, string $seed): Collection
    {
        return $questions
            ->sortBy(static fn (Model $question): string => md5($seed.':'.$question->getKey()))
            ->values();
    }

    private function seed(ExamSection $section, Student $student): string
    {
        return $section->getKey().'-'.$student->getKey();
    }

    private function perQuestionScore(ExamSection $section, int $count): float
    {
        return $count > 0 ? round(((float) $section->score) / $count, 2) : 0.0;
    }

    /** An explicit per-question score wins over the section's even split. */
    private function scoreFor(ExamSection $section, int $questionId, float $fallback): float
    {
        if ($section->selection_mode === SelectionMode::Random) {
            return $fallback;
        }

        $explicit = $section->questions
            ->firstWhere('question_id', $questionId)?->score;

        return $explicit === null ? $fallback : (float) $explicit;
    }
}

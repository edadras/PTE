<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Assessment\Models\Answer;
use App\Domain\Identity\Models\Student;
use App\Domain\Learning\Enums\Difficulty;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Enums\QuestionStatus;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Enums\SelectionMode;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Support\ModuleRegistry;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Picks the questions a student practises next.
 *
 * Three modes, all of them constrained to questions that are published, were
 * approved, and belong to a module the academy has switched on — a student must
 * never be shown a draft or a question from a disabled module.
 *
 * @see docs/05-modules-exams-practice.md §4
 */
final class QuestionSelector
{
    /** How many recent answers define the student's current level. */
    private const LEVEL_WINDOW = 10;

    private const ADAPTIVE_LOWER = 0.10;

    private const ADAPTIVE_UPPER = 0.15;

    /** Probability of deliberately handing over something harder. */
    private const CHALLENGE_CHANCE = 20;

    private const CHALLENGE_SHIFT = 0.15;

    /**
     * @param  array{
     *     mode?: SelectionMode|BackedEnum|string,
     *     bank_id?: int|null,
     *     types?: array<int, QuestionType|string>,
     *     difficulty?: Difficulty|string|null,
     *     tags?: array<int, string>,
     *     exclude?: array<int, int>,
     *     repeat_after_days?: int,
     *     allow_repeats?: bool
     * }  $options
     * @return Collection<int, Question>
     */
    public function select(ModuleKey|QuestionType $for, Student $student, int $count, array $options = []): Collection
    {
        $count = max(0, $count);

        if ($count === 0) {
            return new Collection;
        }

        $types = $this->resolveTypes($for, $options);

        if ($types === []) {
            return new Collection;
        }

        $mode = SelectionMode::fromMixed($options['mode'] ?? null);
        $cooled = $this->cooledDownQuestionIds($student, $options);
        $excluded = array_values(array_unique(array_merge(
            array_map('intval', $options['exclude'] ?? []),
            $cooled
        )));

        $query = $this->query($types, $options, $excluded);

        $selected = match ($mode) {
            SelectionMode::Sequential => $this->sequential($query, $student, $types, $count),
            SelectionMode::Adaptive => $this->adaptive($query, $student, $types, $count),
            SelectionMode::Random => $this->random($query, $count),
        };

        // A small bank plus a long cooldown must not produce an empty session:
        // fall back to the least recently practised of the excluded questions.
        if ($selected->count() < $count && $cooled !== []) {
            $selected = $selected->concat(
                $this->topUp($types, $options, $cooled, $selected->pluck('id')->all(), $count - $selected->count())
            );
        }

        return $selected->values();
    }

    /**
     * The student's current level for a type: a weighted mean of recent score
     * ratios where the most recent answer counts most.
     */
    public function levelFor(Student $student, QuestionType $type): float
    {
        $answers = Answer::query()
            ->where('student_id', $student->getKey())
            ->whereNotNull('score')
            ->where('max_score', '>', 0)
            ->whereIn('question_id', Question::query()->where('type', $type)->select('id'))
            ->orderByDesc('id')
            ->limit(self::LEVEL_WINDOW)
            ->get(['score', 'max_score']);

        if ($answers->isEmpty()) {
            return 0.5;
        }

        $weightedSum = 0.0;
        $weightTotal = 0.0;
        $position = $answers->count();

        foreach ($answers as $answer) {
            $weight = (float) $position--;
            $ratio = min(1.0, max(0.0, (float) $answer->score / (float) $answer->max_score));

            $weightedSum += $ratio * $weight;
            $weightTotal += $weight;
        }

        return $weightTotal > 0.0 ? $weightedSum / $weightTotal : 0.5;
    }

    /**
     * @param  array<int, QuestionType>  $types
     * @param  array<string, mixed>  $options
     * @param  array<int, int>  $excluded
     * @return Builder<Question>
     */
    private function query(array $types, array $options, array $excluded): Builder
    {
        $query = Question::query()
            ->where('status', QuestionStatus::Published)
            // Published is the workflow's end state, but the approval stamp is
            // the audit trail — require both.
            ->whereNotNull('approved_at')
            ->whereIn('type', array_map(static fn (QuestionType $t): string => $t->value, $types))
            ->whereIn('module_key', $this->enabledModuleValues());

        if (! empty($options['bank_id'])) {
            $query->where('bank_id', (int) $options['bank_id']);
        }

        if (! empty($options['difficulty'])) {
            $difficulty = $options['difficulty'] instanceof Difficulty
                ? $options['difficulty']
                : Difficulty::tryFrom((string) $options['difficulty']);

            if ($difficulty instanceof Difficulty) {
                $query->where('difficulty', $difficulty->value);
            }
        }

        foreach ($options['tags'] ?? [] as $tag) {
            $query->whereJsonContains('tags', (string) $tag);
        }

        if ($excluded !== []) {
            $query->whereNotIn('id', $excluded);
        }

        return $query;
    }

    /**
     * @param  Builder<Question>  $query
     * @return Collection<int, Question>
     */
    private function random(Builder $query, int $count): Collection
    {
        return $query->inRandomOrder()->limit($count)->get();
    }

    /**
     * Sequential means "carry on where the student left off", wrapping to the
     * start of the bank once the end is reached.
     *
     * @param  Builder<Question>  $query
     * @param  array<int, QuestionType>  $types
     * @return Collection<int, Question>
     */
    private function sequential(Builder $query, Student $student, array $types, int $count): Collection
    {
        $cursor = $this->lastAnsweredQuestionId($student, $types);

        $ahead = (clone $query)
            ->when($cursor !== null, static fn (Builder $q): Builder => $q->where('id', '>', $cursor))
            ->orderBy('id')
            ->limit($count)
            ->get();

        if ($ahead->count() >= $count || $cursor === null) {
            return $ahead;
        }

        $wrapped = (clone $query)
            ->where('id', '<=', $cursor)
            ->whereNotIn('id', $ahead->pluck('id')->all())
            ->orderBy('id')
            ->limit($count - $ahead->count())
            ->get();

        return $ahead->concat($wrapped);
    }

    /**
     * @param  Builder<Question>  $query
     * @param  array<int, QuestionType>  $types
     * @return Collection<int, Question>
     */
    private function adaptive(Builder $query, Student $student, array $types, int $count): Collection
    {
        $selected = new Collection;
        $taken = [];

        foreach (range(1, $count) as $ignored) {
            $type = $types[array_rand($types)];
            $level = $this->levelFor($student, $type);

            [$low, $high] = $this->targetWindow($level);

            $question = (clone $query)
                ->when($taken !== [], static fn (Builder $q): Builder => $q->whereNotIn('id', $taken))
                ->whereBetween('difficulty_index', [$low, $high])
                ->inRandomOrder()
                ->first();

            // Nothing in the band — take whatever is closest to the target so the
            // session still runs.
            $question ??= (clone $query)
                ->when($taken !== [], static fn (Builder $q): Builder => $q->whereNotIn('id', $taken))
                ->orderByRaw('abs(difficulty_index - ?)', [round(($low + $high) / 2, 2)])
                ->first();

            if (! $question instanceof Question) {
                break;
            }

            $selected->push($question);
            $taken[] = $question->getKey();
        }

        return $selected;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function targetWindow(float $level): array
    {
        $challenge = random_int(1, 100) <= self::CHALLENGE_CHANCE;
        $centre = $level + ($challenge ? self::CHALLENGE_SHIFT : 0.0);

        return [
            max(0.0, round($centre - self::ADAPTIVE_LOWER, 2)),
            min(1.0, round($centre + self::ADAPTIVE_UPPER, 2)),
        ];
    }

    /**
     * Questions this student answered too recently to see again.
     *
     * @param  array<string, mixed>  $options
     * @return array<int, int>
     */
    private function cooledDownQuestionIds(Student $student, array $options): array
    {
        if (($options['allow_repeats'] ?? false) === true) {
            return [];
        }

        $days = (int) ($options['repeat_after_days'] ?? 30);

        if ($days <= 0) {
            return [];
        }

        return Answer::query()
            ->where('student_id', $student->getKey())
            ->where('created_at', '>=', Carbon::now()->subDays($days))
            ->distinct()
            ->pluck('question_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, QuestionType>  $types
     * @param  array<string, mixed>  $options
     * @param  array<int, int>  $candidates
     * @param  array<int, int>  $alreadyPicked
     * @return Collection<int, Question>
     */
    private function topUp(array $types, array $options, array $candidates, array $alreadyPicked, int $count): Collection
    {
        $ordered = Answer::query()
            ->whereIn('question_id', $candidates)
            ->selectRaw('question_id, max(id) as last_answer_id')
            ->groupBy('question_id')
            ->orderBy('last_answer_id')
            ->pluck('question_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->reject(static fn (int $id): bool => in_array($id, $alreadyPicked, true))
            ->take($count)
            ->all();

        if ($ordered === []) {
            return new Collection;
        }

        return $this->query($types, $options, [])
            ->whereIn('id', $ordered)
            ->get()
            ->sortBy(static fn (Question $question): int => (int) array_search($question->getKey(), $ordered, true))
            ->values();
    }

    /**
     * @param  array<int, QuestionType>  $types
     */
    private function lastAnsweredQuestionId(Student $student, array $types): ?int
    {
        $id = Answer::query()
            ->where('student_id', $student->getKey())
            ->whereIn('question_id', Question::query()
                ->whereIn('type', array_map(static fn (QuestionType $t): string => $t->value, $types))
                ->select('id'))
            ->orderByDesc('id')
            ->value('question_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<int, QuestionType>
     */
    private function resolveTypes(ModuleKey|QuestionType $for, array $options): array
    {
        $requested = [];

        foreach ($options['types'] ?? [] as $type) {
            $resolved = $type instanceof QuestionType ? $type : QuestionType::tryFrom((string) $type);

            if ($resolved instanceof QuestionType) {
                $requested[] = $resolved;
            }
        }

        $candidates = $for instanceof QuestionType ? [$for] : $for->questionTypes();

        if ($requested !== []) {
            $candidates = array_values(array_filter(
                $candidates,
                static fn (QuestionType $type): bool => in_array($type, $requested, true)
            ));
        }

        $enabled = $this->enabledModuleValues();

        return array_values(array_filter(
            $candidates,
            static fn (QuestionType $type): bool => in_array($type->module()->value, $enabled, true)
        ));
    }

    /**
     * @return array<int, string>
     */
    private function enabledModuleValues(): array
    {
        $academy = TenantContext::get();

        if (! $academy instanceof Academy) {
            return array_map(static fn (ModuleKey $m): string => $m->value, ModuleKey::cases());
        }

        return array_map(
            static fn (ModuleKey $module): string => $module->value,
            ModuleRegistry::enabledFor($academy)
        );
    }
}

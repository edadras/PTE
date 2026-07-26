<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Assessment\Data\QuestionContext;
use App\Domain\Assessment\Enums\SessionStatus;
use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Identity\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Database\Factories\ExamSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A student's attempt at an exam.
 *
 * Everything the attempt is graded against lives in `snapshot`, taken once at
 * start; the exam row itself may change afterwards without invalidating results.
 *
 * @property int $id
 * @property int $academy_id
 * @property int $exam_id
 * @property int $student_id
 * @property int $attempt_number
 * @property SessionStatus $status
 * @property int|null $current_section_id
 * @property array<string, mixed>|null $snapshot
 * @property Carbon|null $started_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $scored_at
 * @property float|null $total_score
 * @property array<string, mixed>|null $section_scores
 * @property bool|null $passed
 */
final class ExamSession extends Model
{
    /** @use HasFactory<ExamSessionFactory> */
    use BelongsToAcademy;

    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'status' => SessionStatus::class,
            'snapshot' => 'array',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'expires_at' => 'datetime',
            'scored_at' => 'datetime',
            'total_score' => 'float',
            'section_scores' => 'array',
            'passed' => 'boolean',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class, 'session_id')
            ->where('answers.session_type', SessionType::Exam->value);
    }

    /** @param  Builder<self>  $query */
    public function scopeOverdue(Builder $query, ?Carbon $moment = null): Builder
    {
        return $query
            ->where('status', SessionStatus::InProgress->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $moment ?? now());
    }

    public function sessionType(): SessionType
    {
        return SessionType::Exam;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function hasExpired(?Carbon $moment = null): bool
    {
        return $this->expires_at !== null && ($moment ?? now())->greaterThanOrEqualTo($this->expires_at);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sectionSnapshots(): array
    {
        $sections = data_get($this->snapshot ?? [], 'sections', []);

        return is_array($sections) ? array_values($sections) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function sectionSnapshot(int $sectionId): ?array
    {
        foreach ($this->sectionSnapshots() as $section) {
            if ((int) ($section['id'] ?? 0) === $sectionId) {
                return $section;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function questionSnapshot(int $questionId): ?array
    {
        foreach ($this->sectionSnapshots() as $section) {
            foreach ((array) ($section['questions'] ?? []) as $question) {
                if ((int) ($question['question_id'] ?? 0) === $questionId) {
                    return $question;
                }
            }
        }

        return null;
    }

    /** The frozen grading context for one question of this attempt. */
    public function contextFor(int $questionId): ?QuestionContext
    {
        $question = $this->questionSnapshot($questionId);

        return $question === null ? null : QuestionContext::fromArray($question);
    }

    public function totalQuestions(): int
    {
        return array_sum(array_map(
            static fn (array $section): int => count((array) ($section['questions'] ?? [])),
            $this->sectionSnapshots()
        ));
    }

    public function passingScore(): ?float
    {
        $value = data_get($this->snapshot ?? [], 'exam.passing_score');

        return $value === null ? null : (float) $value;
    }

    public function maxScore(): float
    {
        return (float) data_get($this->snapshot ?? [], 'exam.total_score', 0);
    }
}

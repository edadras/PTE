<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Assessment\Data\QuestionContext;
use App\Domain\Assessment\Data\ScoreResult;
use App\Domain\Assessment\Enums\ScoredBy;
use App\Domain\Assessment\Enums\ScoringStatus;
use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Identity\Models\Student;
use App\Domain\Learning\Enums\AnswerKind;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Models\Question;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Database\Factories\AnswerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One submitted question, in either a practice or an exam session.
 *
 * @property int $id
 * @property int $academy_id
 * @property SessionType $session_type
 * @property int $session_id
 * @property int $question_id
 * @property int $student_id
 * @property array<string, mixed>|null $answer_data
 * @property string|null $media_path
 * @property string|null $transcript
 * @property array<string, mixed>|null $transcript_meta
 * @property float|null $score
 * @property float|null $max_score
 * @property array<string, mixed>|null $breakdown
 * @property array<string, mixed>|null $feedback
 * @property int|null $ai_request_id
 * @property float|null $confidence
 * @property ScoringStatus $scoring_status
 * @property ScoredBy|null $scored_by
 * @property Carbon|null $scored_at
 * @property bool $graded_manually
 * @property float|null $original_ai_score
 * @property string|null $override_reason
 * @property int|null $overridden_by
 */
final class Answer extends Model
{
    /** @use HasFactory<AnswerFactory> */
    use BelongsToAcademy;

    use HasFactory;

    protected $guarded = ['id'];

    /**
     * Grading context resolved once per answer.
     *
     * Not persisted: for an exam it comes from the session snapshot, for
     * practice from the live question. Keeping it off the attributes means a
     * scorer can be unit-tested without touching the database.
     */
    private ?QuestionContext $context = null;

    protected function casts(): array
    {
        return [
            'session_type' => SessionType::class,
            'answer_data' => 'array',
            'transcript_meta' => 'array',
            'breakdown' => 'array',
            'feedback' => 'array',
            'score' => 'float',
            'max_score' => 'float',
            'confidence' => 'float',
            'scoring_status' => ScoringStatus::class,
            'scored_by' => ScoredBy::class,
            'scored_at' => 'datetime',
            'graded_manually' => 'boolean',
            'original_ai_score' => 'float',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function practiceSession(): BelongsTo
    {
        return $this->belongsTo(PracticeSession::class, 'session_id');
    }

    public function examSession(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeForSession(Builder $query, SessionType $type, int $sessionId): Builder
    {
        return $query->where('session_type', $type->value)->where('session_id', $sessionId);
    }

    /** @param  Builder<self>  $query */
    public function scopeAwaitingScore(Builder $query): Builder
    {
        return $query->whereIn('scoring_status', [
            ScoringStatus::Pending->value,
            ScoringStatus::Scoring->value,
        ]);
    }

    public function attachContext(QuestionContext $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Falls back to the loaded question, which is the practice path — an exam
     * always attaches the snapshot context explicitly.
     */
    public function context(): QuestionContext
    {
        if ($this->context instanceof QuestionContext) {
            return $this->context;
        }

        $question = $this->relationLoaded('question') ? $this->getRelation('question') : $this->question;

        if (! $question instanceof Model) {
            throw new RuntimeException(
                "Answer {$this->getKey()} has no question context to score against."
            );
        }

        return $this->context = QuestionContext::fromQuestion(
            $question,
            $this->max_score !== null ? (float) $this->max_score : null
        );
    }

    public function questionType(): QuestionType
    {
        return $this->context()->type;
    }

    public function answerKind(): AnswerKind
    {
        return $this->questionType()->answerKind();
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->answer_data ?? [];
    }

    public function payloadValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->answer_data ?? [], $key, $default);
    }

    public function isSkipped(): bool
    {
        return (bool) $this->payloadValue('skipped', false);
    }

    public function effectiveMaxScore(): float
    {
        return (float) ($this->max_score ?? $this->context()->maxScore);
    }

    /** Writes a scorer result onto the model without saving it. */
    public function applyScore(ScoreResult $result, ScoredBy $by): self
    {
        $this->score = $result->score;
        $this->max_score = $result->maxScore;
        $this->breakdown = $result->breakdown;
        $this->feedback = $result->feedback;
        $this->confidence = $result->confidence;
        $this->scoring_status = ScoringStatus::Scored;
        $this->scored_by = $by;
        $this->scored_at = now();

        return $this;
    }

    public function percentage(): float
    {
        $max = (float) ($this->max_score ?? 0.0);

        return $max > 0.0 ? round(((float) $this->score) / $max * 100, 2) : 0.0;
    }
}

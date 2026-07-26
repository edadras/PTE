<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Assessment\Enums\SessionStatus;
use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Identity\Models\Student;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Database\Factories\PracticeSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $academy_id
 * @property int $student_id
 * @property ModuleKey $module_key
 * @property QuestionType|null $question_type
 * @property SessionStatus $status
 * @property int $total_questions
 * @property int $answered
 * @property float|null $total_score
 * @property float|null $max_score
 * @property array<int, int>|null $question_ids
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int|null $duration_seconds
 */
final class PracticeSession extends Model
{
    /** @use HasFactory<PracticeSessionFactory> */
    use BelongsToAcademy;
    use HasFactory;

    protected $guarded = ['id'];

    /** Models live outside App\\Models, so the factory is named explicitly. */
    protected static function newFactory(): PracticeSessionFactory
    {
        return PracticeSessionFactory::new();
    }

    protected function casts(): array
    {
        return [
            'module_key' => ModuleKey::class,
            'question_type' => QuestionType::class,
            'status' => SessionStatus::class,
            'total_questions' => 'integer',
            'answered' => 'integer',
            'total_score' => 'float',
            'max_score' => 'float',
            'question_ids' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class, 'session_id')
            ->where('answers.session_type', SessionType::Practice->value);
    }

    public function score(): HasMany
    {
        return $this->hasMany(Score::class, 'session_id')
            ->where('scores.session_type', SessionType::Practice->value);
    }

    /** @param  Builder<self>  $query */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', SessionStatus::InProgress->value);
    }

    public function sessionType(): SessionType
    {
        return SessionType::Practice;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /** Next question id in the frozen queue, or null when the set is exhausted. */
    public function nextQuestionId(): ?int
    {
        $queue = $this->question_ids ?? [];
        $next = $queue[$this->answered] ?? null;

        return $next === null ? null : (int) $next;
    }

    public function percentage(): float
    {
        $max = (float) ($this->max_score ?? 0.0);

        return $max > 0.0 ? round(((float) $this->total_score) / $max * 100, 2) : 0.0;
    }
}

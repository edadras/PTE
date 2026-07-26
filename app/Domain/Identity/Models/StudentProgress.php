<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Materialised aggregates per student and question type — the dashboard and
 * the bot read these instead of scanning answers.
 *
 * @property int $academy_id
 * @property int $student_id
 * @property string $module_key
 * @property string $question_type
 *
 * @see docs/07-database-schema.md §4
 */
final class StudentProgress extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    protected $table = 'student_progress';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'avg_score' => 'float',
            'best_score' => 'float',
            'last_score' => 'float',
            'streak_days' => 'integer',
            'total_time_seconds' => 'integer',
            'last_practiced_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function module(): ?ModuleKey
    {
        return ModuleKey::tryFrom($this->module_key);
    }
}

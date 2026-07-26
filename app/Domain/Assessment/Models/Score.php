<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Identity\Models\Student;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Session-level summary. `published_at` is what an academy that requires teacher
 * approval before releasing grades toggles — the row exists either way.
 *
 * @property int $id
 * @property int $academy_id
 * @property int $student_id
 * @property SessionType $session_type
 * @property int $session_id
 * @property ModuleKey|null $module_key
 * @property float $raw_score
 * @property float|null $scaled_score
 * @property float $percentage
 * @property array<string, mixed>|null $breakdown
 * @property Carbon|null $published_at
 */
final class Score extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'session_type' => SessionType::class,
            'module_key' => ModuleKey::class,
            'raw_score' => 'float',
            'scaled_score' => 'float',
            'percentage' => 'float',
            'breakdown' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeForSession(Builder $query, SessionType $type, int $sessionId): Builder
    {
        return $query->where('session_type', $type->value)->where('session_id', $sessionId);
    }

    /** @param  Builder<self>  $query */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Learning\Models;

use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $academy_id
 * @property int $course_id
 * @property string $title
 * @property array<string, mixed>|null $content
 * @property string|null $media_path
 * @property int|null $duration_minutes
 * @property int $sort_order
 * @property bool $is_free
 * @property CourseStatus $status
 */
final class Lesson extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<LessonFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', CourseStatus::Published);
    }

    protected static function newFactory(): LessonFactory
    {
        return LessonFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'status' => CourseStatus::class,
            'is_free' => 'boolean',
            'duration_minutes' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}

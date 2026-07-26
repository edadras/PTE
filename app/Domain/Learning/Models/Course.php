<?php

declare(strict_types=1);

namespace App\Domain\Learning\Models;

use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $academy_id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property ModuleKey|null $module_key
 * @property string|null $cover_path
 * @property string $price
 * @property string $currency
 * @property int|null $duration_days
 * @property CourseStatus $status
 * @property int $sort_order
 */
final class Course extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('sort_order');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', CourseStatus::Published);
    }

    protected static function newFactory(): CourseFactory
    {
        return CourseFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'module_key' => ModuleKey::class,
            'status' => CourseStatus::class,
            'price' => 'decimal:2',
            'duration_days' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}

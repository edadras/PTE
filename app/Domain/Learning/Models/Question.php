<?php

declare(strict_types=1);

namespace App\Domain\Learning\Models;

use App\Domain\Learning\Data\QuestionContent;
use App\Domain\Learning\Enums\Difficulty;
use App\Domain\Learning\Enums\MediaKind;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Enums\QuestionStatus;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $academy_id
 * @property int $bank_id
 * @property ModuleKey $module_key
 * @property QuestionType $type
 * @property Difficulty $difficulty
 * @property float $difficulty_index
 * @property string|null $title
 * @property array<string, mixed> $content
 * @property array<string, mixed>|null $correct_answer
 * @property array<string, mixed>|null $metadata
 * @property array<int, string>|null $tags
 * @property QuestionStatus $status
 * @property int $usage_count
 * @property float|null $avg_score
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $published_at
 * @property string|null $import_batch_id
 */
final class Question extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'bank_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('sort_order');
    }

    public function media(): HasMany
    {
        return $this->hasMany(QuestionMedia::class);
    }

    public function audio(): HasOne
    {
        return $this->hasOne(QuestionMedia::class)->where('kind', MediaKind::Audio);
    }

    public function image(): HasOne
    {
        return $this->hasOne(QuestionMedia::class)->where('kind', MediaKind::Image);
    }

    /** The typed view of the raw `content` JSON. */
    public function typedContent(): QuestionContent
    {
        return QuestionContent::make($this->type, $this->content ?? []);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', QuestionStatus::Published);
    }

    /**
     * @param  Builder<self>  $query
     * @param  array<int, ModuleKey|string>  $modules
     * @return Builder<self>
     */
    public function scopeForModules(Builder $query, array $modules): Builder
    {
        $values = array_map(
            static fn (ModuleKey|string $module): string => $module instanceof ModuleKey ? $module->value : $module,
            $modules
        );

        return $query->whereIn('module_key', $values);
    }

    protected static function newFactory(): QuestionFactory
    {
        return QuestionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'module_key' => ModuleKey::class,
            'type' => QuestionType::class,
            'difficulty' => Difficulty::class,
            'status' => QuestionStatus::class,
            'content' => 'array',
            'correct_answer' => 'array',
            'metadata' => 'array',
            'tags' => 'array',
            'difficulty_index' => 'float',
            'avg_score' => 'float',
            'usage_count' => 'integer',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}

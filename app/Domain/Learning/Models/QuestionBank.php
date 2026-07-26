<?php

declare(strict_types=1);

namespace App\Domain\Learning\Models;

use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Database\Factories\QuestionBankFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $academy_id
 * @property string $name
 * @property ModuleKey|null $module_key
 * @property string|null $description
 * @property bool $is_default
 * @property int $question_count
 */
final class QuestionBank extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<QuestionBankFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'bank_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /** Keeps the denormalised counter honest after bulk operations. */
    public function refreshQuestionCount(): void
    {
        $this->forceFill(['question_count' => $this->questions()->count()])->save();
    }

    protected static function newFactory(): QuestionBankFactory
    {
        return QuestionBankFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'module_key' => ModuleKey::class,
            'is_default' => 'boolean',
            'question_count' => 'integer',
        ];
    }
}

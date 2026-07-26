<?php

declare(strict_types=1);

namespace App\Domain\Learning\Models;

use App\Domain\Learning\Enums\MediaKind;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Database\Factories\QuestionMediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $academy_id
 * @property int $question_id
 * @property MediaKind $kind
 * @property string $s3_path
 * @property string|null $mime
 * @property int|null $size_bytes
 * @property int|null $duration_ms
 * @property string|null $transcript
 * @property string|null $telegram_file_id
 * @property int|null $telegram_bot_id
 * @property \Illuminate\Support\Carbon|null $cached_at
 */
final class QuestionMedia extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<QuestionMediaFactory> */
    use HasFactory;

    protected $table = 'question_media';

    protected $guarded = ['id'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * A cached file_id is only reusable by the bot that uploaded it; anything
     * else has to be re-uploaded from storage.
     */
    public function reusableFileIdFor(?int $botId): ?string
    {
        if ($botId === null || $this->telegram_bot_id !== $botId) {
            return null;
        }

        return $this->telegram_file_id;
    }

    protected static function newFactory(): QuestionMediaFactory
    {
        return QuestionMediaFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MediaKind::class,
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'telegram_bot_id' => 'integer',
            'cached_at' => 'datetime',
        ];
    }
}

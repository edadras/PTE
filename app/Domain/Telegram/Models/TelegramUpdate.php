<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Models;

use App\Domain\Telegram\Enums\UpdateType;
use App\Domain\Telegram\Support\TokenRedactor;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The raw envelope, stored before any processing so a failed update can be
 * replayed instead of lost.
 *
 * @property int $id
 * @property int $telegram_bot_id
 * @property int $update_id
 * @property UpdateType $type
 * @property array<string, mixed> $payload
 */
final class TelegramUpdate extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'update_id' => 'integer',
            'type' => UpdateType::class,
            'payload' => 'array',
            'chat_id' => 'integer',
            'telegram_user_id' => 'integer',
            'attempts' => 'integer',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TelegramBot, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class, 'telegram_bot_id');
    }

    public function markProcessed(): void
    {
        $this->forceFill(['processed_at' => now(), 'error' => null])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'error' => TokenRedactor::redact($error),
            'attempts' => $this->attempts + 1,
        ])->save();
    }

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('processed_at');
    }
}

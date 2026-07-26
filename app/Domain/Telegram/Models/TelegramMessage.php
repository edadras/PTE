<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Models;

use App\Domain\Telegram\Enums\MessageDirection;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $chat_id
 * @property MessageDirection $direction
 * @property string|null $content
 */
final class TelegramMessage extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'chat_id' => 'integer',
            'student_id' => 'integer',
            'telegram_message_id' => 'integer',
            'broadcast_id' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TelegramBot, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class, 'telegram_bot_id');
    }

    /** @return BelongsTo<Broadcast, $this> */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', MessageDirection::In);
    }

    /** @param  Builder<self>  $query */
    public function scopeOutbound(Builder $query): Builder
    {
        return $query->where('direction', MessageDirection::Out);
    }
}

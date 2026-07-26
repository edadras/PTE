<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's override of a platform message. Absence of a row (or
 * `is_customized = false`) means "use the platform default".
 *
 * @property int $academy_id
 * @property string $key
 * @property string $locale
 * @property string $channel
 * @property string|null $content
 * @property bool $is_customized
 *
 * @see docs/03-white-label.md §6
 */
final class MessageTemplate extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    /**
     * The keys the platform ships defaults for.
     *
     * @var array<int, string>
     */
    public const KEYS = [
        'welcome',
        'menu_header',
        'practice_started',
        'practice_completed',
        'score_ready',
        'exam_reminder',
        'daily_nudge',
        'subscription_expiring',
        'subscription_expired',
        'payment_success',
        'support_greeting',
        'error_generic',
        'quota_exceeded',
    ];

    public const CHANNEL_TELEGRAM = 'telegram';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_customized' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCustomized(Builder $query): Builder
    {
        return $query->where('is_customized', true)->whereNotNull('content');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForKey(Builder $query, string $key, string $locale, string $channel = self::CHANNEL_TELEGRAM): Builder
    {
        return $query
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('channel', $channel);
    }

    public function isUsable(): bool
    {
        return $this->is_customized && filled($this->content);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Models;

use App\Domain\Telegram\Enums\BotHealthStatus;
use App\Domain\Telegram\Support\TokenRedactor;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Database\Factories\TelegramBotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The academy's bot.
 *
 * Three fields here are credentials; all three are `encrypted` cast *and*
 * `$hidden`, so neither a JSON response nor a `dd($bot)` can leak them
 * (docs/12 §3).
 *
 * @property int $id
 * @property int $academy_id
 * @property string $public_id
 * @property string|null $token
 * @property string|null $token_last4
 * @property int|null $bot_user_id
 * @property string|null $username
 * @property string|null $webhook_secret
 * @property string|null $webhook_url
 * @property bool $is_active
 * @property BotHealthStatus $health_status
 */
final class TelegramBot extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<TelegramBotFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * Never serialised. The encrypted cast protects the database; this protects
     * every API response, log line and debug dump.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'token',
        'webhook_secret',
        'payments_provider_token',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'payments_provider_token' => 'encrypted',
            'bot_user_id' => 'integer',
            'is_active' => 'boolean',
            'health_status' => BotHealthStatus::class,
            'consecutive_failures' => 'integer',
            'pending_update_count' => 'integer',
            'settings' => 'array',
            'webhook_registered_at' => 'datetime',
            'last_error_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $bot): void {
            if (blank($bot->public_id)) {
                $bot->public_id = (string) Str::ulid();
            }
        });
    }

    protected static function newFactory(): TelegramBotFactory
    {
        return TelegramBotFactory::new();
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return HasMany<TelegramUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(TelegramUpdate::class);
    }

    /** @return HasMany<TelegramMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(TelegramMessage::class);
    }

    /**
     * Set the token and keep the display suffix in sync.
     *
     * Doing this in one place is what guarantees token_last4 never drifts from
     * the real token after a rotation.
     */
    public function setToken(string $token): void
    {
        $this->token = $token;
        $this->token_last4 = mb_substr($token, -4);
    }

    public function maskedToken(): string
    {
        return TokenRedactor::mask($this->token_last4);
    }

    public function webhookUrl(): string
    {
        $base = rtrim((string) config('pte.platform.webhook_base_url'), '/');

        return "{$base}/webhook/{$this->public_id}";
    }

    public function deepLink(string $payload = ''): string
    {
        $link = 'https://t.me/'.(string) $this->username;

        return $payload === '' ? $link : $link.'?start='.$payload;
    }

    public function isConnected(): bool
    {
        return $this->is_active && filled($this->token) && filled($this->webhook_secret);
    }

    public function recordError(string $message): void
    {
        $this->forceFill([
            'last_error' => TokenRedactor::redact($message),
            'last_error_at' => now(),
        ])->save();
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

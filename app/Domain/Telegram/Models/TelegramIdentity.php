<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Models;

use App\Domain\Identity\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Database\Factories\TelegramIdentityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Telegram user as seen by one academy's bot.
 *
 * The same human talking to two academies gets two rows — which is exactly what
 * tenant isolation requires.
 *
 * @property int $id
 * @property int $academy_id
 * @property int|null $student_id
 * @property int $telegram_user_id
 * @property int $chat_id
 * @property bool $is_blocked
 */
final class TelegramIdentity extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<TelegramIdentityFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'chat_id' => 'integer',
            'student_id' => 'integer',
            'is_bot' => 'boolean',
            'is_blocked' => 'boolean',
            'blocked_at' => 'datetime',
            'linked_at' => 'datetime',
            'last_interaction_at' => 'datetime',
            'consented_at' => 'datetime',
        ];
    }

    protected static function newFactory(): TelegramIdentityFactory
    {
        return TelegramIdentityFactory::new();
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function fullName(): string
    {
        return trim(((string) $this->first_name).' '.((string) $this->last_name));
    }

    public function displayName(): string
    {
        $name = $this->fullName();

        if ($name !== '') {
            return $name;
        }

        return filled($this->username) ? '@'.$this->username : (string) $this->telegram_user_id;
    }

    public function isLinked(): bool
    {
        return $this->student_id !== null;
    }

    public function markBlocked(): void
    {
        $this->forceFill(['is_blocked' => true, 'blocked_at' => now()])->save();
    }

    public function markUnblocked(): void
    {
        $this->forceFill(['is_blocked' => false, 'blocked_at' => null])->save();
    }

    public function touchInteraction(): void
    {
        $this->forceFill(['last_interaction_at' => now()])->saveQuietly();
    }

    /** @param  Builder<self>  $query */
    public function scopeReachable(Builder $query): Builder
    {
        return $query->where('is_blocked', false);
    }
}

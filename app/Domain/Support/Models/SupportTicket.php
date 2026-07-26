<?php

declare(strict_types=1);

namespace App\Domain\Support\Models;

use App\Domain\Identity\Models\Student;
use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketSource;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use App\Models\User;
use Database\Factories\SupportTicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $academy_id
 * @property int|null $student_id
 * @property string $subject
 * @property TicketStatus $status
 * @property TicketPriority $priority
 * @property TicketSource $source
 * @property int|null $assigned_to
 * @property int|null $closed_by
 * @property Carbon|null $last_reply_at
 * @property Carbon|null $closed_at
 * @property array<string, mixed>|null $meta
 *
 * @see docs/07-database-schema.md §10
 */
final class SupportTicket extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<SupportTicketFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'source' => TicketSource::class,
            'student_id' => 'integer',
            'assigned_to' => 'integer',
            'closed_by' => 'integer',
            'meta' => 'array',
            'last_reply_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** Models live under app/Domain, so the default factory guesser misses. */
    protected static function newFactory(): SupportTicketFactory
    {
        return SupportTicketFactory::new();
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<SupportTicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [TicketStatus::Open->value, TicketStatus::Pending->value]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAssignedTo(Builder $query, User|int $user): Builder
    {
        return $query->where('assigned_to', $user instanceof User ? $user->getKey() : $user);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isClosed(): bool
    {
        return $this->status === TicketStatus::Closed;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Support\Models;

use App\Domain\Support\Enums\TicketSender;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $academy_id
 * @property int $ticket_id
 * @property TicketSender $sender_type
 * @property int|null $sender_id
 * @property string $content
 * @property array<int, array<string, mixed>>|null $attachments
 * @property bool $is_internal
 * @property Carbon|null $created_at
 */
final class SupportTicketMessage extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sender_type' => TicketSender::class,
            'sender_id' => 'integer',
            'ticket_id' => 'integer',
            'attachments' => 'array',
            'is_internal' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SupportTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    /**
     * Messages the student is allowed to see.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleToStudent(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }

    public function isFromStaff(): bool
    {
        return $this->sender_type->isStaff();
    }
}

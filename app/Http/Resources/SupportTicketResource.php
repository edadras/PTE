<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Support\Models\SupportTicket;
use Illuminate\Http\Request;

/**
 * @mixin SupportTicket
 */
final class SupportTicketResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'subject' => $this->subject,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'source' => $this->source->value,
            'last_reply_at' => $this->last_reply_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Support\Data;

use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketSource;

final readonly class OpenTicketData
{
    /**
     * @param  array<int, array<string, mixed>>  $attachments
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $subject,
        public string $message,
        public ?int $studentId = null,
        public TicketPriority $priority = TicketPriority::Normal,
        public TicketSource $source = TicketSource::Telegram,
        public ?int $assignedTo = null,
        public array $attachments = [],
        public array $meta = [],
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            subject: (string) ($attributes['subject'] ?? ''),
            message: (string) ($attributes['message'] ?? ''),
            studentId: isset($attributes['student_id']) ? (int) $attributes['student_id'] : null,
            priority: $attributes['priority'] instanceof TicketPriority
                ? $attributes['priority']
                : TicketPriority::tryFrom((string) ($attributes['priority'] ?? '')) ?? TicketPriority::Normal,
            source: $attributes['source'] instanceof TicketSource
                ? $attributes['source']
                : TicketSource::tryFrom((string) ($attributes['source'] ?? '')) ?? TicketSource::Telegram,
            assignedTo: isset($attributes['assigned_to']) ? (int) $attributes['assigned_to'] : null,
            attachments: is_array($attributes['attachments'] ?? null) ? $attributes['attachments'] : [],
            meta: is_array($attributes['meta'] ?? null) ? $attributes['meta'] : [],
        );
    }
}

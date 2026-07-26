<?php

declare(strict_types=1);

namespace App\Domain\Support\Data;

use App\Domain\Telegram\Enums\MessageDirection;
use Illuminate\Support\Carbon;

/**
 * One line of bot transcript as support sees it.
 *
 * A flattened copy rather than the model itself: this is handed to a panel view
 * and to the API, and neither should be able to reach `telegram_messages`
 * relations (and from there another tenant's bot) through it.
 */
final readonly class ConversationEntry
{
    public function __construct(
        public int $id,
        public MessageDirection $direction,
        public string $type,
        public string $content,
        public string $status,
        public ?string $error,
        public ?Carbon $at,
    ) {}

    public function isFromStudent(): bool
    {
        return $this->direction === MessageDirection::In;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction->value,
            'type' => $this->type,
            'content' => $this->content,
            'status' => $this->status,
            'error' => $this->error,
            'at' => $this->at?->toIso8601String(),
        ];
    }
}

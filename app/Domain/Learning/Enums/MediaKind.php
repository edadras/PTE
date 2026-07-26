<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

enum MediaKind: string
{
    case Audio = 'audio';
    case Image = 'image';
    case Video = 'video';

    public function label(): string
    {
        return __('learning.media_kind.'.$this->value);
    }

    /**
     * @return array<int, string>
     */
    public function allowedMimeTypes(): array
    {
        return match ($this) {
            self::Audio => ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/webm'],
            self::Image => ['image/jpeg', 'image/png', 'image/webp'],
            self::Video => ['video/mp4', 'video/webm', 'video/quicktime'],
        };
    }

    /** Telegram sendX method this maps to. */
    public function telegramMethod(): string
    {
        return match ($this) {
            self::Audio => 'sendAudio',
            self::Image => 'sendPhoto',
            self::Video => 'sendVideo',
        };
    }
}

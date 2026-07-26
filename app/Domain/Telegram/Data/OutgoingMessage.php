<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Data;

/**
 * Everything needed to put one message on the wire.
 *
 * Serialisable to a plain array so it can ride inside a queued job payload
 * without dragging models along.
 */
final readonly class OutgoingMessage
{
    public const KIND_TEXT = 'text';

    public const KIND_PHOTO = 'photo';

    public const KIND_AUDIO = 'audio';

    public const KIND_VOICE = 'voice';

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public int $chatId,
        public string $text = '',
        public string $kind = self::KIND_TEXT,
        public ?string $file = null,
        public ?array $replyMarkup = null,
        public ?string $parseMode = 'HTML',
        public bool $disableWebPagePreview = true,
        public ?int $replyToMessageId = null,
        public ?int $broadcastId = null,
        public ?int $studentId = null,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public static function text(int $chatId, string $text, ?array $replyMarkup = null): self
    {
        return new self(chatId: $chatId, text: $text, replyMarkup: $replyMarkup);
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public static function photo(int $chatId, string $file, string $caption = '', ?array $replyMarkup = null): self
    {
        return new self(
            chatId: $chatId,
            text: $caption,
            kind: self::KIND_PHOTO,
            file: $file,
            replyMarkup: $replyMarkup,
        );
    }

    public static function voice(int $chatId, string $file, string $caption = ''): self
    {
        return new self(chatId: $chatId, text: $caption, kind: self::KIND_VOICE, file: $file);
    }

    public static function audio(int $chatId, string $file, string $caption = ''): self
    {
        return new self(chatId: $chatId, text: $caption, kind: self::KIND_AUDIO, file: $file);
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function withKeyboard(?array $replyMarkup): self
    {
        return new self(
            chatId: $this->chatId,
            text: $this->text,
            kind: $this->kind,
            file: $this->file,
            replyMarkup: $replyMarkup,
            parseMode: $this->parseMode,
            disableWebPagePreview: $this->disableWebPagePreview,
            replyToMessageId: $this->replyToMessageId,
            broadcastId: $this->broadcastId,
            studentId: $this->studentId,
            extra: $this->extra,
        );
    }

    public function forChat(int $chatId, ?int $studentId = null): self
    {
        return new self(
            chatId: $chatId,
            text: $this->text,
            kind: $this->kind,
            file: $this->file,
            replyMarkup: $this->replyMarkup,
            parseMode: $this->parseMode,
            disableWebPagePreview: $this->disableWebPagePreview,
            replyToMessageId: $this->replyToMessageId,
            broadcastId: $this->broadcastId,
            studentId: $studentId ?? $this->studentId,
            extra: $this->extra,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'chat_id' => $this->chatId,
            'text' => $this->text,
            'kind' => $this->kind,
            'file' => $this->file,
            'reply_markup' => $this->replyMarkup,
            'parse_mode' => $this->parseMode,
            'disable_web_page_preview' => $this->disableWebPagePreview,
            'reply_to_message_id' => $this->replyToMessageId,
            'broadcast_id' => $this->broadcastId,
            'student_id' => $this->studentId,
            'extra' => $this->extra,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed>|null $markup */
        $markup = is_array($data['reply_markup'] ?? null) ? $data['reply_markup'] : null;

        /** @var array<string, mixed> $extra */
        $extra = is_array($data['extra'] ?? null) ? $data['extra'] : [];

        return new self(
            chatId: (int) ($data['chat_id'] ?? 0),
            text: (string) ($data['text'] ?? ''),
            kind: (string) ($data['kind'] ?? self::KIND_TEXT),
            file: isset($data['file']) && is_string($data['file']) ? $data['file'] : null,
            replyMarkup: $markup,
            parseMode: isset($data['parse_mode']) && is_string($data['parse_mode']) ? $data['parse_mode'] : null,
            disableWebPagePreview: (bool) ($data['disable_web_page_preview'] ?? true),
            replyToMessageId: isset($data['reply_to_message_id']) ? (int) $data['reply_to_message_id'] : null,
            broadcastId: isset($data['broadcast_id']) ? (int) $data['broadcast_id'] : null,
            studentId: isset($data['student_id']) ? (int) $data['student_id'] : null,
            extra: $extra,
        );
    }
}

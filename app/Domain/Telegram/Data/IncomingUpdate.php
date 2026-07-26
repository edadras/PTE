<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Data;

use App\Domain\Telegram\Enums\UpdateType;

/**
 * A flattened view of a raw Bot API update.
 *
 * The router and the flow engine should never dig through `data_get($payload,
 * 'callback_query.message.chat.id')` themselves — one place to get it wrong is
 * enough.
 */
final readonly class IncomingUpdate
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public int $updateId,
        public UpdateType $type,
        public array $payload,
        public ?int $chatId,
        public ?int $userId,
        public ?string $text,
        public ?string $callbackData,
        public ?string $callbackQueryId,
        public ?int $messageId,
        public ?string $languageCode,
        public ?string $username,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $chatType,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $type = UpdateType::detect($payload);

        $envelope = match ($type) {
            UpdateType::CallbackQuery => $payload['callback_query'] ?? [],
            UpdateType::EditedMessage => $payload['edited_message'] ?? [],
            UpdateType::MyChatMember, UpdateType::ChatMember => $payload[$type->value] ?? [],
            UpdateType::InlineQuery => $payload['inline_query'] ?? [],
            UpdateType::PreCheckoutQuery => $payload['pre_checkout_query'] ?? [],
            default => $payload['message'] ?? [],
        };

        $message = $type === UpdateType::CallbackQuery
            ? (is_array($envelope['message'] ?? null) ? $envelope['message'] : [])
            : (is_array($envelope) ? $envelope : []);

        $from = is_array($envelope['from'] ?? null) ? $envelope['from'] : [];
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];

        // pre_checkout_query has no chat; fall back to the payer.
        $chatId = self::intOrNull($chat['id'] ?? null) ?? self::intOrNull($from['id'] ?? null);

        return new self(
            updateId: (int) ($payload['update_id'] ?? 0),
            type: $type,
            payload: $payload,
            chatId: $chatId,
            userId: self::intOrNull($from['id'] ?? null),
            text: self::stringOrNull($message['text'] ?? $message['caption'] ?? null),
            callbackData: $type === UpdateType::CallbackQuery
                ? self::stringOrNull($envelope['data'] ?? null)
                : null,
            callbackQueryId: $type === UpdateType::CallbackQuery
                ? self::stringOrNull($envelope['id'] ?? null)
                : null,
            messageId: self::intOrNull($message['message_id'] ?? null),
            languageCode: self::stringOrNull($from['language_code'] ?? null),
            username: self::stringOrNull($from['username'] ?? null),
            firstName: self::stringOrNull($from['first_name'] ?? null),
            lastName: self::stringOrNull($from['last_name'] ?? null),
            chatType: self::stringOrNull($chat['type'] ?? null),
        );
    }

    public function isPrivateChat(): bool
    {
        return $this->chatType === 'private' || $this->chatType === null;
    }

    /** The `/command` part of the text, lowercased and without the @botname suffix. */
    public function command(): ?string
    {
        if ($this->text === null || ! str_starts_with($this->text, '/')) {
            return null;
        }

        $first = explode(' ', trim($this->text))[0];
        $first = explode('@', $first)[0];

        return mb_strtolower($first);
    }

    /** Everything after the command word — `/start ref_ABC` yields `ref_ABC`. */
    public function commandArgument(): ?string
    {
        if ($this->command() === null || $this->text === null) {
            return null;
        }

        $parts = explode(' ', trim($this->text), 2);

        return isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : null;
    }

    /**
     * The media object attached to this message, if any.
     *
     * @return array{kind: string, data: array<string, mixed>}|null
     */
    public function media(): ?array
    {
        $message = is_array($this->payload['message'] ?? null) ? $this->payload['message'] : [];

        foreach (['voice', 'audio', 'video_note', 'photo', 'document', 'video'] as $kind) {
            if (! isset($message[$kind])) {
                continue;
            }

            $data = $message[$kind];

            // photo arrives as an array of sizes; the last one is the largest.
            if ($kind === 'photo' && is_array($data)) {
                $data = end($data);
            }

            if (is_array($data)) {
                return ['kind' => $kind, 'data' => $data];
            }
        }

        return null;
    }

    public function fileId(): ?string
    {
        $media = $this->media();

        return $media === null ? null : self::stringOrNull($media['data']['file_id'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function successfulPayment(): array
    {
        $payment = data_get($this->payload, 'message.successful_payment');

        return is_array($payment) ? $payment : [];
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

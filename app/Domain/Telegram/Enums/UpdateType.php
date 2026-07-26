<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Enums;

/**
 * The Bot API update kinds we subscribe to (config `pte.telegram.allowed_updates`),
 * plus a catch-all so an unexpected update is still stored rather than dropped.
 */
enum UpdateType: string
{
    case Message = 'message';
    case EditedMessage = 'edited_message';
    case CallbackQuery = 'callback_query';
    case InlineQuery = 'inline_query';
    case PreCheckoutQuery = 'pre_checkout_query';
    case SuccessfulPayment = 'successful_payment';
    case MyChatMember = 'my_chat_member';
    case ChatMember = 'chat_member';
    case ChannelPost = 'channel_post';
    case Unknown = 'unknown';

    public function label(): string
    {
        return __('telegram.update_type.'.$this->value);
    }

    /**
     * Classify a raw update envelope.
     *
     * successful_payment is not a top-level update key — it arrives nested in a
     * message — but it needs its own branch in the router, so it is promoted.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function detect(array $payload): self
    {
        if (isset($payload['message']['successful_payment'])) {
            return self::SuccessfulPayment;
        }

        foreach (self::cases() as $case) {
            if ($case !== self::Unknown && $case !== self::SuccessfulPayment && isset($payload[$case->value])) {
                return $case;
            }
        }

        return self::Unknown;
    }

    /** Update kinds the conversation engine actually reacts to. */
    public function isConversational(): bool
    {
        return in_array($this, [self::Message, self::EditedMessage, self::CallbackQuery], true);
    }
}

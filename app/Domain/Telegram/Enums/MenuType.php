<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Enums;

/**
 * How a menu is presented to the student.
 *
 * @see docs/04-telegram-layer.md §5
 */
enum MenuType: string
{
    /** Reply keyboard shown under the input box, the bot's home screen. */
    case Main = 'main';

    /** Inline keyboard attached to a specific message. */
    case Inline = 'inline';

    /** Registered with setMyCommands and shown in the "/" menu. */
    case Command = 'command';

    /** Reply keyboard that is never torn down (resize + persistent). */
    case Persistent = 'persistent';

    public function label(): string
    {
        return __('telegram.menu_type.'.$this->value);
    }

    /** Reply keyboards live under the input box; inline ones ride on a message. */
    public function isReplyKeyboard(): bool
    {
        return $this === self::Main || $this === self::Persistent;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Enums;

/**
 * What happens when a student taps a menu item.
 *
 * The payload shape differs per case; see requiredPayloadKeys() and
 * docs/04-telegram-layer.md §5.
 */
enum MenuActionType: string
{
    case OpenUrl = 'open_url';
    case SendMessage = 'send_message';
    case OpenModule = 'open_module';
    case StartPractice = 'start_practice';
    case StartExam = 'start_exam';
    case OpenCourse = 'open_course';
    case BuyPlan = 'buy_plan';
    case ContactSupport = 'contact_support';
    case RunFlow = 'run_flow';
    case OpenWebapp = 'open_webapp';
    case RunCommand = 'run_command';

    public function label(): string
    {
        return __('telegram.menu_action.'.$this->value);
    }

    /**
     * Payload keys that must be present for the action to be executable.
     *
     * @return array<int, string>
     */
    public function requiredPayloadKeys(): array
    {
        return match ($this) {
            self::OpenUrl => ['url'],
            self::SendMessage => ['text'],
            self::OpenModule => ['module'],
            self::StartPractice => ['type'],
            self::StartExam => ['exam_id'],
            self::OpenCourse => ['course_id'],
            self::BuyPlan => ['plan_id'],
            self::RunFlow => ['flow_id'],
            self::OpenWebapp => ['path'],
            self::RunCommand => ['command'],
            self::ContactSupport => [],
        };
    }

    /**
     * Telegram only allows URL/web-app buttons on inline keyboards, so these
     * actions force the item to be rendered inline no matter the menu type.
     */
    public function requiresInlineKeyboard(): bool
    {
        return $this === self::OpenUrl || $this === self::OpenWebapp;
    }

    /** Short token used inside the 64-byte callback_data budget. */
    public function callbackToken(): string
    {
        return match ($this) {
            self::OpenUrl => 'url',
            self::SendMessage => 'msg',
            self::OpenModule => 'mod',
            self::StartPractice => 'prc',
            self::StartExam => 'exm',
            self::OpenCourse => 'crs',
            self::BuyPlan => 'buy',
            self::ContactSupport => 'sup',
            self::RunFlow => 'flw',
            self::OpenWebapp => 'wap',
            self::RunCommand => 'cmd',
        };
    }

    public static function fromCallbackToken(string $token): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->callbackToken() === $token) {
                return $case;
            }
        }

        return null;
    }
}

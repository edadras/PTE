<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Data;

use App\Domain\Telegram\Support\CallbackData;

/**
 * What the UpdateRouter concluded an update means.
 *
 * Returning a decision instead of acting directly keeps the router pure and
 * testable — the handler decides what to do with it.
 *
 * @see docs/04-telegram-layer.md §4
 */
final readonly class RoutingDecision
{
    public const INTENT_DEEP_LINK = 'deep_link';

    public const INTENT_START = 'start';

    public const INTENT_GLOBAL_COMMAND = 'global_command';

    public const INTENT_CALLBACK = 'callback';

    public const INTENT_FLOW_RESUME = 'flow_resume';

    public const INTENT_MENU_ITEM = 'menu_item';

    public const INTENT_PAYMENT = 'payment';

    public const INTENT_CHAT_MEMBER = 'chat_member';

    public const INTENT_FALLBACK = 'fallback';

    public const INTENT_IGNORE = 'ignore';

    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public string $intent,
        public array $context = [],
        public ?CallbackData $callback = null,
        public ?DeepLinkPayload $deepLink = null,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public static function make(string $intent, array $context = []): self
    {
        return new self($intent, $context);
    }

    public static function deepLink(DeepLinkPayload $payload): self
    {
        return new self(self::INTENT_DEEP_LINK, ['payload' => $payload->raw], null, $payload);
    }

    public static function start(): self
    {
        return new self(self::INTENT_START);
    }

    public static function globalCommand(string $command): self
    {
        return new self(self::INTENT_GLOBAL_COMMAND, ['command' => $command]);
    }

    public static function callback(CallbackData $data): self
    {
        return new self(self::INTENT_CALLBACK, [], $data);
    }

    public static function flowResume(int $flowId, ?string $nodeKey): self
    {
        return new self(self::INTENT_FLOW_RESUME, ['flow_id' => $flowId, 'node_id' => $nodeKey]);
    }

    public static function menuItem(int $menuItemId): self
    {
        return new self(self::INTENT_MENU_ITEM, ['menu_item_id' => $menuItemId]);
    }

    public static function fallback(): self
    {
        return new self(self::INTENT_FALLBACK);
    }

    public static function ignore(string $reason = ''): self
    {
        return new self(self::INTENT_IGNORE, ['reason' => $reason]);
    }

    public function is(string $intent): bool
    {
        return $this->intent === $intent;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }
}

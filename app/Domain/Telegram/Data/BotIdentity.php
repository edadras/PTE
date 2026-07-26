<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Data;

/**
 * The useful half of a getMe response.
 */
final readonly class BotIdentity
{
    public function __construct(
        public int $id,
        public string $username,
        public string $firstName,
        public bool $canJoinGroups = false,
        public bool $canReadAllGroupMessages = false,
        public bool $supportsInlineQueries = false,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public static function fromApi(array $result): self
    {
        return new self(
            id: (int) ($result['id'] ?? 0),
            username: (string) ($result['username'] ?? ''),
            firstName: (string) ($result['first_name'] ?? ''),
            canJoinGroups: (bool) ($result['can_join_groups'] ?? false),
            canReadAllGroupMessages: (bool) ($result['can_read_all_group_messages'] ?? false),
            supportsInlineQueries: (bool) ($result['supports_inline_queries'] ?? false),
        );
    }

    public function link(): string
    {
        return 'https://t.me/'.$this->username;
    }
}

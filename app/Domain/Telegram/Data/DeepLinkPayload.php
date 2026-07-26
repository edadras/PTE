<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Data;

/**
 * A parsed `?start=` payload.
 *
 * @see docs/04-telegram-layer.md §8
 */
final readonly class DeepLinkPayload
{
    public const KIND_REFERRAL = 'referral';

    public const KIND_INVITE = 'invite';

    public const KIND_EXAM = 'exam';

    public const KIND_COURSE = 'course';

    public const KIND_CAMPAIGN = 'campaign';

    public const KIND_UNKNOWN = 'unknown';

    public function __construct(
        public string $kind,
        public string $value,
        public string $raw,
    ) {}

    public function isKnown(): bool
    {
        return $this->kind !== self::KIND_UNKNOWN;
    }

    public function intValue(): ?int
    {
        return is_numeric($this->value) ? (int) $this->value : null;
    }

    /** The acquisition `source` recorded against the student. */
    public function acquisitionSource(): string
    {
        return match ($this->kind) {
            self::KIND_REFERRAL => 'referral',
            self::KIND_INVITE => 'invite',
            self::KIND_CAMPAIGN => 'campaign',
            self::KIND_EXAM, self::KIND_COURSE => 'direct_link',
            default => 'organic',
        };
    }
}

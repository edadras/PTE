<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\Data\DeepLinkPayload;

/**
 * Parses and builds `https://t.me/<bot>?start=<payload>` payloads.
 *
 * Telegram allows at most 64 characters of `A-Za-z0-9_-`, which is why long
 * targets are shortened to an opaque token rather than embedded directly.
 *
 * @see docs/04-telegram-layer.md §8
 */
final class DeepLinkResolver
{
    public const MAX_LENGTH = 64;

    private const PREFIXES = [
        'ref_' => DeepLinkPayload::KIND_REFERRAL,
        'inv_' => DeepLinkPayload::KIND_INVITE,
        'exam_' => DeepLinkPayload::KIND_EXAM,
        'course_' => DeepLinkPayload::KIND_COURSE,
        'cmp_' => DeepLinkPayload::KIND_CAMPAIGN,
    ];

    public function resolve(?string $raw): ?DeepLinkPayload
    {
        if ($raw === null) {
            return null;
        }

        $raw = trim($raw);

        if (! $this->isWellFormed($raw)) {
            return null;
        }

        foreach (self::PREFIXES as $prefix => $kind) {
            if (str_starts_with($raw, $prefix)) {
                $value = substr($raw, strlen($prefix));

                return $value === ''
                    ? null
                    : new DeepLinkPayload(kind: $kind, value: $value, raw: $raw);
            }
        }

        return new DeepLinkPayload(
            kind: DeepLinkPayload::KIND_UNKNOWN,
            value: $raw,
            raw: $raw,
        );
    }

    public function isWellFormed(string $raw): bool
    {
        return $raw !== ''
            && strlen($raw) <= self::MAX_LENGTH
            && preg_match('/^[A-Za-z0-9_-]+$/', $raw) === 1;
    }

    public function build(string $kind, string|int $value): ?string
    {
        $prefix = array_search($kind, self::PREFIXES, true);

        if ($prefix === false) {
            return null;
        }

        $payload = $prefix.$value;

        return $this->isWellFormed($payload) ? $payload : null;
    }

    public function referral(string $studentCode): ?string
    {
        return $this->build(DeepLinkPayload::KIND_REFERRAL, $studentCode);
    }

    public function invite(string $token): ?string
    {
        return $this->build(DeepLinkPayload::KIND_INVITE, $token);
    }

    public function exam(int $examId): ?string
    {
        return $this->build(DeepLinkPayload::KIND_EXAM, $examId);
    }

    public function course(int $courseId): ?string
    {
        return $this->build(DeepLinkPayload::KIND_COURSE, $courseId);
    }

    public function campaign(string|int $campaignId): ?string
    {
        return $this->build(DeepLinkPayload::KIND_CAMPAIGN, $campaignId);
    }

    /** Full shareable URL for a bot username. */
    public function url(string $botUsername, string $payload): string
    {
        return 'https://t.me/'.ltrim($botUsername, '@').'?start='.$payload;
    }
}

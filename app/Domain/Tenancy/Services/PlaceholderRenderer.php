<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Data\PlaceholderContext;

/**
 * Interpolates `{placeholder}` tokens in tenant-authored copy.
 *
 * Two rules keep this safe and predictable:
 *  - every substituted value is escaped, because the text is authored by a
 *    tenant and rendered in the panel and in bot messages;
 *  - an unknown placeholder is left exactly as written, so a typo shows up as
 *    `{frist_name}` instead of silently disappearing.
 *
 * @see docs/03-white-label.md §5, §8
 */
final class PlaceholderRenderer
{
    private const PATTERN = '/\{(\w+)\}/';

    /**
     * Placeholders offered by the panel's live preview, grouped.
     *
     * @var array<string, array<int, string>>
     */
    public const GROUPS = [
        'student' => ['first_name', 'last_name', 'full_name', 'student_code', 'level'],
        'academy' => ['academy_name', 'support_phone', 'website', 'instagram'],
        'time' => ['today', 'time', 'weekday'],
        'progress' => ['total_practices', 'avg_score', 'streak_days', 'last_score'],
        'subscription' => ['plan_name', 'days_remaining', 'expires_at'],
    ];

    public function render(string $template, PlaceholderContext $context): string
    {
        return $this->replace($template, $context, escape: true);
    }

    /**
     * Same substitution without HTML escaping — for channels that apply their
     * own escaping (Telegram MarkdownV2) *after* rendering.
     */
    public function renderRaw(string $template, PlaceholderContext $context): string
    {
        return $this->replace($template, $context, escape: false);
    }

    /**
     * Placeholders used in the template that the context cannot fill.
     *
     * @return array<int, string>
     */
    public function unknown(string $template, PlaceholderContext $context): array
    {
        return array_values(array_unique(array_filter(
            $this->found($template),
            static fn (string $name): bool => ! $context->has($name)
        )));
    }

    /**
     * @return array<int, string>
     */
    public function found(string $template): array
    {
        preg_match_all(self::PATTERN, $template, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * @return array<int, string>
     */
    public static function supported(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    private function replace(string $template, PlaceholderContext $context, bool $escape): string
    {
        $rendered = preg_replace_callback(
            self::PATTERN,
            static function (array $matches) use ($context, $escape): string {
                $name = $matches[1];

                if (! $context->has($name)) {
                    return $matches[0];
                }

                $value = $context->get($name);

                return $escape ? e($value) : $value;
            },
            $template
        );

        return $rendered ?? $template;
    }
}

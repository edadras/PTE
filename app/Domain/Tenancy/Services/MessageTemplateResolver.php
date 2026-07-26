<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Data\PlaceholderContext;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\Models\MessageTemplate;
use App\Domain\Tenancy\TenantContext;

/**
 * Returns the copy an academy has customised, or the platform default.
 *
 * Falling back to the language file (instead of copying defaults into every
 * tenant at creation time) is what lets an improved default text reach every
 * academy that never edited it.
 *
 * @see docs/03-white-label.md §6
 */
final class MessageTemplateResolver
{
    /** Language-file namespace holding the platform defaults. */
    private const DEFAULTS_NAMESPACE = 'academy.templates';

    public function __construct(private readonly PlaceholderRenderer $renderer) {}

    public function resolve(
        string $key,
        ?string $locale = null,
        string $channel = MessageTemplate::CHANNEL_TELEGRAM,
        ?Academy $academy = null,
    ): string {
        $academy ??= TenantContext::get();
        $locale ??= $this->defaultLocale($academy);

        $custom = $this->customized($key, $locale, $channel, $academy);

        if ($custom !== null) {
            return $custom;
        }

        return $this->platformDefault($key, $locale);
    }

    /**
     * Resolve and interpolate in one step — what callers usually want.
     */
    public function render(
        string $key,
        PlaceholderContext $context,
        ?string $locale = null,
        string $channel = MessageTemplate::CHANNEL_TELEGRAM,
        ?Academy $academy = null,
        bool $escape = true,
    ): string {
        $template = $this->resolve($key, $locale, $channel, $academy);

        return $escape
            ? $this->renderer->render($template, $context)
            : $this->renderer->renderRaw($template, $context);
    }

    public function isCustomized(string $key, ?string $locale = null, string $channel = MessageTemplate::CHANNEL_TELEGRAM, ?Academy $academy = null): bool
    {
        $academy ??= TenantContext::get();

        return $this->customized($key, $locale ?? $this->defaultLocale($academy), $channel, $academy) !== null;
    }

    public function platformDefault(string $key, string $locale): string
    {
        $translationKey = self::DEFAULTS_NAMESPACE.'.'.$key;

        $translated = __($translationKey, [], $locale);

        // Laravel echoes the key back when a translation is missing.
        return is_string($translated) && $translated !== $translationKey ? $translated : '';
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return MessageTemplate::KEYS;
    }

    private function customized(string $key, string $locale, string $channel, ?Academy $academy): ?string
    {
        if (! $academy instanceof Academy) {
            return null;
        }

        $template = MessageTemplate::query()
            ->withoutGlobalScope('academy')
            ->where('academy_id', $academy->getKey())
            ->forKey($key, $locale, $channel)
            ->customized()
            ->first();

        return $template?->content;
    }

    private function defaultLocale(?Academy $academy): string
    {
        return $academy?->preferredLocale() ?? (string) app()->getLocale();
    }
}

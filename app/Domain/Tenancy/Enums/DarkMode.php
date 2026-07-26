<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Enums;

/**
 * @see docs/03-white-label.md §2
 */
enum DarkMode: string
{
    case Light = 'light';
    case Dark = 'dark';
    case Auto = 'auto';

    public function label(): string
    {
        return __("academy.dark_mode.{$this->value}");
    }

    /** Filament only needs to know whether the toggle should exist at all. */
    public function allowsDarkTheme(): bool
    {
        return $this !== self::Light;
    }
}

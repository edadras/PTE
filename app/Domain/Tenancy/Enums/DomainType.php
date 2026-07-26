<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Enums;

/**
 * @see docs/01-multi-tenancy.md §4.2
 */
enum DomainType: string
{
    case Subdomain = 'subdomain';
    case Custom = 'custom';

    public function label(): string
    {
        return __("academy.domain_type.{$this->value}");
    }

    /** Custom domains need DNS proof of ownership; our own subdomains do not. */
    public function requiresVerification(): bool
    {
        return $this === self::Custom;
    }
}

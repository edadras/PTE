<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * The four roles every academy is born with. They are `is_system = true`:
 * an academy may copy them but never delete them.
 *
 * Super Admin is deliberately absent — it is a column on `users`, not a role.
 *
 * @see docs/02-roles-and-rbac.md §2
 */
enum SystemRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Teacher = 'teacher';
    case Support = 'support';

    /** The stored `roles.name` — what spatie checks against. */
    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return __("roles.{$this->value}.name");
    }

    public function description(): string
    {
        return __("roles.{$this->value}.description");
    }

    public function color(): string
    {
        return match ($this) {
            self::Owner => 'warning',
            self::Manager => 'info',
            self::Teacher => 'success',
            self::Support => 'primary',
        };
    }

    /** Higher wins; used to stop a lower role from editing a higher one. */
    public function level(): int
    {
        return match ($this) {
            self::Owner => 100,
            self::Manager => 70,
            self::Teacher => 40,
            self::Support => 30,
        };
    }

    public static function tryFromKey(string $key): ?self
    {
        return self::tryFrom(strtolower(trim($key)));
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

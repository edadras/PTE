<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Identity\Support\PermissionCatalog;
use App\Models\User;

/**
 * Feeds the custom-role builder.
 *
 * Two rules from docs/02 §5 are enforced here and nowhere else in the panel:
 * `platform.*` never appears in an academy picker, and nobody may hand out a
 * permission they do not themselves hold (Privilege Escalation Guard).
 */
final class PermissionOptions
{
    /**
     * Grouped, label-resolved options an actor is allowed to grant.
     *
     * @return array<string, array<string, string>> group key => [permission => label]
     */
    public static function grantableGroups(?User $actor): array
    {
        $groups = [];

        foreach (PermissionCatalog::academyGroups() as $group => $permissions) {
            $options = [];

            foreach ($permissions as $permission) {
                if (! self::mayGrant($actor, $permission)) {
                    continue;
                }

                $options[$permission] = PermissionCatalog::labelFor($permission);
            }

            if ($options !== []) {
                $groups[(string) $group] = $options;
            }
        }

        return $groups;
    }

    /**
     * Split a flat permission list into the per-group buckets the form uses.
     *
     * @param  array<int, string>  $permissions
     * @return array<string, array<int, string>>
     */
    public static function toGroupedState(?User $actor, array $permissions): array
    {
        $state = [];

        foreach (self::grantableGroups($actor) as $group => $options) {
            $state[$group] = array_values(array_intersect($permissions, array_keys($options)));
        }

        return $state;
    }

    /**
     * @param  array<string, array<int, string>|null>  $grouped
     * @return array<int, string>
     */
    public static function fromGroupedState(?User $actor, array $grouped): array
    {
        $flat = [];

        foreach ($grouped as $permissions) {
            foreach ((array) $permissions as $permission) {
                $flat[] = (string) $permission;
            }
        }

        return self::sanitise($actor, array_values(array_unique($flat)));
    }

    /**
     * Flat allow-list used to sanitise a submitted role.
     *
     * @return array<int, string>
     */
    public static function grantable(?User $actor): array
    {
        return array_values(array_filter(
            PermissionCatalog::academyScoped(),
            static fn (string $permission): bool => self::mayGrant($actor, $permission),
        ));
    }

    /**
     * @param  array<int, string>  $submitted
     * @return array<int, string>
     */
    public static function sanitise(?User $actor, array $submitted): array
    {
        $allowed = self::grantable($actor);

        return array_values(array_intersect(
            PermissionCatalog::onlyAcademyScoped($submitted),
            $allowed,
        ));
    }

    public static function mayGrant(?User $actor, string $permission): bool
    {
        if (PermissionCatalog::scopeOf($permission) !== PermissionCatalog::SCOPE_ACADEMY) {
            return false;
        }

        if (! $actor instanceof User) {
            return false;
        }

        return $actor->can($permission);
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * The closed list of API key scopes, exactly the strings the `api.scope`
 * middleware checks on routes/api/academy.php.
 *
 * @see docs/08-api-and-integrations.md §2
 */
final class ApiScopes
{
    /** @var array<int, string> */
    public const ALL = [
        'students:read',
        'students:write',
        'exams:read',
        'scores:read',
        'questions:write',
        'reports:read',
        'telegram:send',
    ];

    /**
     * @return array<string, string> scope => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::ALL as $scope) {
            $options[$scope] = $scope.' — '.__('panel.api_keys.scopes.'.str_replace(':', '_', $scope));
        }

        return $options;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Support;

/**
 * Evaluates the `condition` JSON on a flow edge (and on `condition` nodes)
 * against the flow's variable bag.
 *
 *     { "var": "level", "op": "eq", "value": "beginner" }
 *     { "all": [ {...}, {...} ] }
 *     { "any": [ {...}, {...} ] }
 *     { "not": {...} }
 *
 * Intentionally not an expression language: the panel builds these visually,
 * and an eval-shaped feature reachable from tenant input is a liability.
 */
final class ConditionEvaluator
{
    /**
     * @param  array<array-key, mixed>|null  $condition
     * @param  array<string, mixed>  $variables
     */
    public static function passes(?array $condition, array $variables): bool
    {
        if ($condition === null || $condition === []) {
            return true;
        }

        if (isset($condition['all']) && is_array($condition['all'])) {
            foreach ($condition['all'] as $child) {
                if (! is_array($child) || ! self::passes($child, $variables)) {
                    return false;
                }
            }

            return true;
        }

        if (isset($condition['any']) && is_array($condition['any'])) {
            foreach ($condition['any'] as $child) {
                if (is_array($child) && self::passes($child, $variables)) {
                    return true;
                }
            }

            return false;
        }

        if (isset($condition['not']) && is_array($condition['not'])) {
            return ! self::passes($condition['not'], $variables);
        }

        return self::comparison($condition, $variables);
    }

    /**
     * @param  array<array-key, mixed>  $condition
     * @param  array<string, mixed>  $variables
     */
    private static function comparison(array $condition, array $variables): bool
    {
        $var = $condition['var'] ?? null;

        if (! is_string($var)) {
            return false;
        }

        $actual = data_get($variables, $var);
        $expected = $condition['value'] ?? null;
        $op = is_string($condition['op'] ?? null) ? $condition['op'] : 'eq';

        return match ($op) {
            'eq' => self::scalarEquals($actual, $expected),
            'neq' => ! self::scalarEquals($actual, $expected),
            'gt' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            'gte' => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            'lt' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            'lte' => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            'in' => is_array($expected) && self::inList($actual, $expected),
            'not_in' => is_array($expected) && ! self::inList($actual, $expected),
            'contains' => is_string($actual) && is_string($expected)
                && mb_stripos($actual, $expected) !== false,
            'empty' => blank($actual),
            'not_empty' => filled($actual),
            'exists' => array_key_exists($var, $variables),
            default => false,
        };
    }

    /** Loose on type, strict on value — flow variables arrive as strings from Telegram. */
    private static function scalarEquals(mixed $actual, mixed $expected): bool
    {
        if (is_scalar($actual) && is_scalar($expected)) {
            return (string) $actual === (string) $expected;
        }

        return $actual === $expected;
    }

    /**
     * @param  array<array-key, mixed>  $list
     */
    private static function inList(mixed $actual, array $list): bool
    {
        foreach ($list as $candidate) {
            if (self::scalarEquals($actual, $candidate)) {
                return true;
            }
        }

        return false;
    }
}

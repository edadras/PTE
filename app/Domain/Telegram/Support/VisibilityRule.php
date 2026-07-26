<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Support;

use App\Domain\Telegram\Data\VisibilityContext;

/**
 * Evaluator for the `visibility_rule` JSON DSL on menu items.
 *
 *     { "all": [ { "subscription": "active" },
 *                { "module_enabled": "pte_speaking" },
 *                { "student_level_in": ["intermediate", "advanced"] },
 *                { "not": { "trial_expired": true } } ] }
 *
 * Combinators: `all` (AND), `any` (OR), `not` (negation).
 * Predicates:  `subscription`, `module_enabled`, `student_level_in`,
 *              `trial_expired`, `registered`.
 *
 * Fail-open on an empty rule (no rule = always visible), fail-closed on an
 * unrecognised predicate — a typo in the panel must not silently expose a
 * button that was meant to be gated.
 *
 * @see docs/04-telegram-layer.md §5
 */
final class VisibilityRule
{
    /**
     * @param  array<string, mixed>|null  $rule
     */
    public static function passes(?array $rule, VisibilityContext $context): bool
    {
        if ($rule === null || $rule === []) {
            return true;
        }

        return self::evaluate($rule, $context);
    }

    /**
     * A rule node is an object with exactly one key; several keys are treated
     * as an implicit AND.
     *
     * @param  array<array-key, mixed>  $node
     */
    private static function evaluate(array $node, VisibilityContext $context): bool
    {
        // A bare list of rules (as found inside `all`/`any`) is an implicit AND.
        if (array_is_list($node)) {
            foreach ($node as $child) {
                if (! is_array($child) || ! self::evaluate($child, $context)) {
                    return false;
                }
            }

            return true;
        }

        foreach ($node as $key => $value) {
            if (! self::evaluateClause((string) $key, $value, $context)) {
                return false;
            }
        }

        return true;
    }

    private static function evaluateClause(string $key, mixed $value, VisibilityContext $context): bool
    {
        return match ($key) {
            'all' => self::all($value, $context),
            'any' => self::any($value, $context),
            'not' => is_array($value) ? ! self::evaluate($value, $context) : true,

            'subscription' => self::subscription($value, $context),
            'module_enabled' => is_string($value) && $context->moduleEnabled($value),
            'student_level_in' => self::levelIn($value, $context),
            'trial_expired' => $context->trialExpired === (bool) $value,
            'registered' => $context->isRegistered === (bool) $value,

            // Unknown predicate: hide rather than guess.
            default => false,
        };
    }

    private static function all(mixed $value, VisibilityContext $context): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $child) {
            if (! is_array($child) || ! self::evaluate($child, $context)) {
                return false;
            }
        }

        return true;
    }

    private static function any(mixed $value, VisibilityContext $context): bool
    {
        if (! is_array($value) || $value === []) {
            return false;
        }

        foreach ($value as $child) {
            if (is_array($child) && self::evaluate($child, $context)) {
                return true;
            }
        }

        return false;
    }

    private static function subscription(mixed $value, VisibilityContext $context): bool
    {
        if (is_array($value)) {
            return in_array($context->subscriptionStatus, array_map('strval', $value), true);
        }

        return is_string($value) && $context->subscriptionStatus === $value;
    }

    private static function levelIn(mixed $value, VisibilityContext $context): bool
    {
        if (! is_array($value) || $context->level === null) {
            return false;
        }

        $levels = array_map(
            static fn (mixed $level): string => mb_strtolower((string) $level),
            $value
        );

        return in_array($context->level, $levels, true);
    }

    /**
     * Static validation for the panel: returns the unsupported keys found in a
     * rule so the builder can flag them before the rule ever gates a button.
     *
     * @param  array<array-key, mixed>  $rule
     * @return array<int, string>
     */
    public static function unsupportedKeys(array $rule): array
    {
        $known = [
            'all', 'any', 'not',
            'subscription', 'module_enabled', 'student_level_in', 'trial_expired', 'registered',
        ];

        $found = [];

        $walk = static function (array $node) use (&$walk, $known, &$found): void {
            foreach ($node as $key => $value) {
                if (is_string($key) && ! in_array($key, $known, true)) {
                    $found[] = $key;
                }

                if (is_array($value)) {
                    $walk($value);
                }
            }
        };

        $walk($rule);

        return array_values(array_unique($found));
    }
}

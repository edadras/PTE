<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Telegram\Enums\MenuActionType;

/**
 * Turns the menu-builder form state into the rows Telegram would actually
 * render, so the panel preview matches the keyboard a student sees.
 *
 * @see docs/04-telegram-layer.md §5
 */
final class MenuPreview
{
    /**
     * @param  array<int|string, array<string, mixed>>  $items
     * @return array<int, array<int, string>>
     */
    public static function rows(array $items): array
    {
        $enabled = array_values(array_filter(
            $items,
            static fn (mixed $item): bool => is_array($item) && (bool) ($item['is_enabled'] ?? true),
        ));

        usort($enabled, static function (array $a, array $b): int {
            return [(int) ($a['row'] ?? 0), (int) ($a['column'] ?? 0), (int) ($a['sort_order'] ?? 0)]
                <=> [(int) ($b['row'] ?? 0), (int) ($b['column'] ?? 0), (int) ($b['sort_order'] ?? 0)];
        });

        $rows = [];

        foreach ($enabled as $item) {
            $row = (int) ($item['row'] ?? 0);
            $rows[$row][] = self::buttonText($item);
        }

        ksort($rows);

        return array_values(array_map(array_values(...), $rows));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function buttonText(array $item): string
    {
        $icon = trim((string) ($item['icon'] ?? ''));
        $label = trim((string) ($item['label'] ?? ''));

        return trim($icon.' '.$label);
    }

    /**
     * Payload keys still missing for an item's action type — surfaced as a
     * warning in the builder rather than discovered by a student.
     *
     * @param  array<string, mixed>  $item
     * @return array<int, string>
     */
    public static function missingPayloadKeys(array $item): array
    {
        $type = $item['action_type'] ?? null;
        $type = $type instanceof MenuActionType ? $type : MenuActionType::tryFrom((string) $type);

        if (! $type instanceof MenuActionType) {
            return [];
        }

        $payload = is_array($item['action_payload'] ?? null) ? $item['action_payload'] : [];

        return array_values(array_filter(
            $type->requiredPayloadKeys(),
            static fn (string $key): bool => blank($payload[$key] ?? null),
        ));
    }
}

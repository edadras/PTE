<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Telegram\Enums\FlowNodeType;

/**
 * Renders the flow graph in the editor as an indented text tree, so an author
 * can see what they wired without a canvas library.
 */
final class FlowPreview
{
    /**
     * @param  array<int|string, mixed>  $nodes  repeater state
     * @param  array<int|string, mixed>  $edges  repeater state
     * @return array{lines: array<int, string>, unreachable: array<int, string>}
     */
    public static function tree(array $nodes, array $edges, ?string $entryKey = null): array
    {
        $byKey = [];

        foreach ($nodes as $node) {
            if (is_array($node) && filled($node['node_key'] ?? null)) {
                $byKey[(string) $node['node_key']] = $node;
            }
        }

        if ($byKey === []) {
            return ['lines' => [], 'unreachable' => []];
        }

        /** @var array<string, array<int, array<string, mixed>>> $adjacency */
        $adjacency = [];

        foreach ($edges as $edge) {
            if (! is_array($edge) || blank($edge['from_node'] ?? null) || blank($edge['to_node'] ?? null)) {
                continue;
            }

            $adjacency[(string) $edge['from_node']][] = $edge;
        }

        $entry = $entryKey !== null && isset($byKey[$entryKey])
            ? $entryKey
            : self::defaultEntry($byKey);

        $lines = [];
        $visited = [];

        self::walk($entry, $byKey, $adjacency, $lines, $visited, depth: 0, edgeLabel: null);

        $unreachable = array_values(array_map(
            static fn (string $key): string => self::describe($byKey[$key]),
            array_diff(array_keys($byKey), array_keys($visited)),
        ));

        return ['lines' => $lines, 'unreachable' => $unreachable];
    }

    /**
     * @param  array<string, array<string, mixed>>  $byKey
     */
    private static function defaultEntry(array $byKey): string
    {
        foreach ($byKey as $key => $node) {
            if (($node['type'] ?? null) === FlowNodeType::Trigger->value) {
                return $key;
            }
        }

        return (string) array_key_first($byKey);
    }

    /**
     * @param  array<string, array<string, mixed>>  $byKey
     * @param  array<string, array<int, array<string, mixed>>>  $adjacency
     * @param  array<int, string>  $lines
     * @param  array<string, bool>  $visited
     */
    private static function walk(
        string $key,
        array $byKey,
        array $adjacency,
        array &$lines,
        array &$visited,
        int $depth,
        ?string $edgeLabel,
    ): void {
        $indent = str_repeat('    ', $depth);
        $prefix = $edgeLabel === null ? '' : '→ '.$edgeLabel.' ';

        if (! isset($byKey[$key])) {
            $lines[] = $indent.$prefix.'⚠ '.$key.' ('.__('panel.flows.preview.missing').')';

            return;
        }

        if (isset($visited[$key])) {
            $lines[] = $indent.$prefix.'↺ '.$key;

            return;
        }

        $visited[$key] = true;
        $lines[] = $indent.$prefix.self::describe($byKey[$key]);

        foreach ($adjacency[$key] ?? [] as $edge) {
            self::walk(
                (string) $edge['to_node'],
                $byKey,
                $adjacency,
                $lines,
                $visited,
                $depth + 1,
                self::edgeLabel($edge),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function describe(array $node): string
    {
        $type = FlowNodeType::tryFrom((string) ($node['type'] ?? ''));
        $label = $type?->label() ?? (string) ($node['type'] ?? '?');
        $title = trim((string) ($node['title'] ?? ''));

        $summary = $title !== '' ? $title : trim(mb_substr((string) data_get($node, 'config.text', ''), 0, 40));

        return sprintf(
            '[%s] %s%s',
            $label,
            (string) ($node['node_key'] ?? '?'),
            $summary === '' ? '' : ' — '.$summary,
        );
    }

    /**
     * @param  array<string, mixed>  $edge
     */
    private static function edgeLabel(array $edge): ?string
    {
        if (filled($edge['label'] ?? null)) {
            return (string) $edge['label'];
        }

        if (($edge['is_default'] ?? false) === true || ($edge['is_default'] ?? null) === '1') {
            return __('panel.flows.preview.default');
        }

        $var = data_get($edge, 'condition.var');

        if (filled($var)) {
            return sprintf(
                '%s %s %s',
                (string) $var,
                (string) data_get($edge, 'condition.op', 'eq'),
                (string) data_get($edge, 'condition.value', ''),
            );
        }

        return null;
    }
}

@php
    /** @var array{lines: array<int, string>, unreachable: array<int, string>} $graph */
@endphp

<div class="space-y-3">
    @if ($graph['lines'] === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('panel.flows.preview.empty') }}</p>
    @else
        <pre dir="ltr" class="overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs leading-6 text-gray-700 dark:bg-white/5 dark:text-gray-300">{{ implode("\n", $graph['lines']) }}</pre>
    @endif

    @if ($graph['unreachable'] !== [])
        <div class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-xs text-warning-700 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-400">
            <p class="font-medium">{{ __('panel.flows.preview.unreachable') }}</p>
            <ul class="mt-1 list-inside list-disc" dir="ltr">
                @foreach ($graph['unreachable'] as $node)
                    <li>{{ $node }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>

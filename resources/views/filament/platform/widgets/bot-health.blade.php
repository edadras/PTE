<x-filament-widgets::widget>
    <x-filament::section :heading="__('panel.stats.bot_health')">
        @php($rows = $this->getRows())

        @if ($rows === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('panel.stats.no_bots') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 text-start">{{ __('panel.academies.singular') }}</th>
                            <th class="py-2 text-start">{{ __('panel.telegram.bot_username') }}</th>
                            <th class="py-2 text-start">{{ __('panel.telegram.health') }}</th>
                            <th class="py-2 text-end">{{ __('panel.telegram.failures') }}</th>
                            <th class="py-2 text-end">{{ __('panel.telegram.pending') }}</th>
                            <th class="py-2 text-start">{{ __('panel.telegram.last_checked') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($rows as $row)
                            <tr @if ($row['error']) title="{{ $row['error'] }}" @endif>
                                <td class="py-2 text-gray-950 dark:text-white">{{ $row['academy'] }}</td>
                                <td class="py-2">{{ $row['username'] ?? '—' }}</td>
                                <td class="py-2">
                                    <x-filament::badge :color="$row['active'] ? $row['status']->color() : 'gray'">
                                        {{ $row['active'] ? $row['status']->label() : __('panel.telegram.inactive') }}
                                    </x-filament::badge>
                                </td>
                                <td class="py-2 text-end tabular-nums">{{ $row['failures'] }}</td>
                                <td class="py-2 text-end tabular-nums">{{ $row['pending'] }}</td>
                                <td class="py-2">{{ $row['checked_at'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

<x-filament-widgets::widget>
    <x-filament::section :heading="__('panel.stats.unprofitable')">
        @php($rows = $this->getRows())

        @if ($rows === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('panel.stats.all_profitable') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-start text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 text-start">{{ __('panel.academies.singular') }}</th>
                            <th class="py-2 text-end">{{ __('panel.stats.ai_cost') }}</th>
                            <th class="py-2 text-end">{{ __('panel.stats.revenue') }}</th>
                            <th class="py-2 text-end">{{ __('panel.stats.ratio') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="py-2 text-gray-950 dark:text-white">{{ $row['name'] }}</td>
                                <td class="py-2 text-end tabular-nums">${{ number_format($row['ai_cost'], 2) }}</td>
                                <td class="py-2 text-end tabular-nums">${{ number_format($row['revenue'], 2) }}</td>
                                <td class="py-2 text-end tabular-nums text-danger-600 dark:text-danger-400">
                                    {{ $row['ratio'] === null ? '∞' : number_format($row['ratio'] * 100, 0) . '%' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

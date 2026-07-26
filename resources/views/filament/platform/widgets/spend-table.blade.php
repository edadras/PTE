<x-filament-widgets::widget>
    <x-filament::section :heading="$this->getHeading()">
        @php($rows = $this->getRows())

        @if ($rows === [])
            <p class="fi-ta-empty-state-description text-sm text-gray-500 dark:text-gray-400">
                {{ __('panel.stats.no_spend') }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="py-2 pe-4 text-gray-700 dark:text-gray-200">{{ $row['label'] }}</td>
                                <td class="py-2 w-32">
                                    <div class="h-1.5 w-full rounded bg-gray-100 dark:bg-white/10">
                                        <div
                                            class="h-1.5 rounded bg-primary-500"
                                            style="width: {{ max(2, (int) round($row['share'] * 100)) }}%"
                                        ></div>
                                    </div>
                                </td>
                                <td class="py-2 ps-4 text-end font-medium tabular-nums text-gray-950 dark:text-white">
                                    ${{ number_format($row['amount'], 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

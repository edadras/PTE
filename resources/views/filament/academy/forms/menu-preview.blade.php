@php
    /** @var array<int, array<int, string>> $rows */
@endphp

<div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5">
    @if ($rows === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('panel.menus.preview_empty') }}</p>
    @else
        <div class="space-y-2">
            @foreach ($rows as $row)
                <div class="flex gap-2">
                    @foreach ($row as $button)
                        <span class="flex-1 truncate rounded-lg bg-white px-3 py-2 text-center text-sm shadow-sm dark:bg-gray-800 dark:text-gray-100">
                            {{ $button }}
                        </span>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif
</div>

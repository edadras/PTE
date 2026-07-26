@php
    /** @var array<string, array<int, string>> $groups */
@endphp

<div class="flex flex-wrap gap-x-6 gap-y-2 text-xs">
    @foreach ($groups as $group => $placeholders)
        <div>
            <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('panel.templates.groups.'.$group) }}:</span>
            <span dir="ltr" class="text-gray-500 dark:text-gray-400">
                @foreach ($placeholders as $placeholder)
                    <code>{{ '{'.$placeholder.'}' }}</code>@if (! $loop->last) · @endif
                @endforeach
            </span>
        </div>
    @endforeach
</div>

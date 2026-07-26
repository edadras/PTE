<div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm dark:border-white/10 dark:bg-white/5">
    <div class="mb-2 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
        {{ $name !== '' ? $name : __('panel.brand.preview') }}
    </div>

    <div class="whitespace-pre-wrap text-gray-900 dark:text-gray-100">{{ $welcome }}</div>

    @if (trim($footer) !== '')
        <hr class="my-3 border-gray-200 dark:border-white/10">
        <div class="whitespace-pre-wrap text-gray-600 dark:text-gray-300">{{ $footer }}</div>
    @endif

    @if ($unknown !== [])
        <div class="mt-3 rounded-lg bg-warning-50 p-2 text-xs text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
            {{ __('panel.brand.unknown_placeholders', ['keys' => implode(', ', $unknown)]) }}
        </div>
    @endif
</div>

<x-filament-panels::page>
    @php($bot = $this->getBot())

    @if ($bot === null)
        <x-filament::section :heading="__('panel.telegram.not_connected')">
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('panel.telegram.not_connected_body') }}</p>
        </x-filament::section>
    @else
        <x-filament::section :heading="__('panel.telegram.status')">
            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('panel.telegram.bot_username') }}</dt>
                    <dd class="font-medium text-gray-950 dark:text-white">
                        {{ $bot->username ? '@' . $bot->username : '—' }}
                    </dd>
                </div>

                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('panel.telegram.field.token') }}</dt>
                    {{-- Never the stored value: the mask plus the last four only. --}}
                    <dd class="font-mono text-gray-950 dark:text-white">{{ $bot->maskedToken() }}</dd>
                </div>

                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('panel.telegram.health') }}</dt>
                    <dd>
                        <x-filament::badge :color="$bot->is_active ? $bot->health_status->color() : 'gray'">
                            {{ $bot->is_active ? $bot->health_status->label() : __('panel.telegram.inactive') }}
                        </x-filament::badge>
                    </dd>
                </div>

                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('panel.telegram.webhook') }}</dt>
                    <dd class="break-all text-gray-950 dark:text-white">{{ $bot->webhookUrl() }}</dd>
                </div>

                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('panel.telegram.registered_at') }}</dt>
                    <dd class="text-gray-950 dark:text-white">{{ $bot->webhook_registered_at ?? '—' }}</dd>
                </div>

                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('panel.telegram.pending') }}</dt>
                    <dd class="text-gray-950 dark:text-white">{{ $bot->pending_update_count ?? 0 }}</dd>
                </div>

                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('panel.telegram.failures') }}</dt>
                    <dd class="text-gray-950 dark:text-white">{{ $bot->consecutive_failures ?? 0 }}</dd>
                </div>

                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('panel.telegram.last_checked') }}</dt>
                    <dd class="text-gray-950 dark:text-white">{{ $bot->last_checked_at ?? '—' }}</dd>
                </div>

                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('panel.telegram.deep_link') }}</dt>
                    <dd class="text-gray-950 dark:text-white">{{ $bot->username ? $bot->deepLink() : '—' }}</dd>
                </div>
            </dl>

            @if ($bot->last_error)
                <div class="mt-4 rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
                    {{ $bot->last_error }}
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>

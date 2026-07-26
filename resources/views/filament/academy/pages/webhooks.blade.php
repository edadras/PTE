<x-filament-panels::page>
    @if ($this->revealedSecret !== null)
        <div class="rounded-xl border border-success-300 bg-success-50 p-4 dark:border-success-500/30 dark:bg-success-500/10">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-success-700 dark:text-success-400">
                        {{ __('panel.webhooks.reveal.title') }}
                    </p>
                    <p class="mt-1 text-xs text-success-700/80 dark:text-success-400/80">
                        {{ __('panel.webhooks.reveal.body') }}
                    </p>
                    <code dir="ltr" class="mt-3 block select-all break-all rounded-lg bg-white p-3 font-mono text-sm text-gray-900 dark:bg-gray-900 dark:text-gray-100">{{ $this->revealedSecret }}</code>
                </div>

                <x-filament::icon-button
                    icon="heroicon-o-x-mark"
                    color="gray"
                    wire:click="dismissRevealedSecret"
                    :label="__('panel.webhooks.reveal.dismiss')"
                />
            </div>
        </div>
    @endif

    <x-filament::section :heading="__('panel.webhooks.title')" :description="__('panel.webhooks.subtitle')">
        <div class="space-y-4">
            @forelse ($this->getWebhooks() as $webhook)
                <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-gray-950 dark:text-white">{{ $webhook->name }}</span>

                                @if ($webhook->isDeliverable())
                                    <x-filament::badge color="success" size="sm">{{ __('panel.common.active') }}</x-filament::badge>
                                @elseif ($webhook->disabled_at !== null)
                                    <x-filament::badge color="danger" size="sm">{{ __('panel.webhooks.auto_disabled') }}</x-filament::badge>
                                @else
                                    <x-filament::badge color="gray" size="sm">{{ __('panel.webhooks.paused') }}</x-filament::badge>
                                @endif
                            </div>

                            <p dir="ltr" class="mt-1 break-all font-mono text-xs text-gray-500 dark:text-gray-400">{{ $webhook->url }}</p>

                            <div class="mt-2 flex flex-wrap gap-1" dir="ltr">
                                @foreach ($webhook->events ?? [] as $event)
                                    <x-filament::badge color="gray" size="sm">{{ $event }}</x-filament::badge>
                                @endforeach
                            </div>

                            @if ($webhook->last_error)
                                <p class="mt-2 text-xs text-danger-600 dark:text-danger-400">{{ $webhook->last_error }}</p>
                            @endif
                        </div>

                        <div class="flex items-center gap-2">
                            {{ ($this->editAction)(['webhook' => $webhook->getKey()]) }}

                            <x-filament::button size="sm" color="gray" outlined wire:click="toggle({{ $webhook->getKey() }})">
                                {{ $webhook->is_active ? __('panel.webhooks.action.pause') : __('panel.webhooks.action.resume') }}
                            </x-filament::button>

                            <x-filament::button
                                size="sm"
                                color="danger"
                                outlined
                                wire:click="remove({{ $webhook->getKey() }})"
                                wire:confirm="{{ __('panel.webhooks.confirm_delete') }}"
                            >
                                {{ __('panel.webhooks.action.delete') }}
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('panel.webhooks.empty') }}</p>
            @endforelse
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('panel.webhooks.deliveries.title')" :description="__('panel.webhooks.deliveries.subtitle')">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="px-3 py-2 text-start">{{ __('panel.webhooks.deliveries.event') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('panel.webhooks.field.name') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('panel.common.status') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('panel.webhooks.deliveries.response') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('panel.webhooks.deliveries.attempt') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('panel.common.created_at') }}</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->getRecentDeliveries() as $delivery)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="px-3 py-3 font-mono text-xs" dir="ltr">{{ $delivery->event }}</td>
                            <td class="px-3 py-3 text-gray-500 dark:text-gray-400">{{ $delivery->webhook?->name ?? '—' }}</td>
                            <td class="px-3 py-3">
                                <x-filament::badge
                                    :color="match ($delivery->status->value) {
                                        'delivered' => 'success',
                                        'pending' => 'gray',
                                        'failed' => 'warning',
                                        default => 'danger',
                                    }"
                                    size="sm"
                                >
                                    {{ $delivery->status->label() }}
                                </x-filament::badge>
                            </td>
                            <td class="px-3 py-3 font-mono text-xs" dir="ltr">{{ $delivery->response_status ?? '—' }}</td>
                            <td class="px-3 py-3">{{ $delivery->attempt }}</td>
                            <td class="px-3 py-3 text-gray-500 dark:text-gray-400">{{ $delivery->created_at?->diffForHumans() }}</td>
                            <td class="px-3 py-3 text-end">
                                <x-filament::button size="sm" color="gray" outlined wire:click="redeliver({{ $delivery->getKey() }})">
                                    {{ __('panel.webhooks.action.redeliver') }}
                                </x-filament::button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-6 text-center text-gray-500 dark:text-gray-400">
                                {{ __('panel.webhooks.deliveries.empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>

<x-filament-panels::page>
    @if ($this->revealedKey !== null)
        <div class="rounded-xl border border-success-300 bg-success-50 p-4 dark:border-success-500/30 dark:bg-success-500/10">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-success-700 dark:text-success-400">
                        {{ __('panel.api_keys.reveal.title') }}
                    </p>
                    <p class="mt-1 text-xs text-success-700/80 dark:text-success-400/80">
                        {{ __('panel.api_keys.reveal.body') }}
                    </p>
                    <code dir="ltr" class="mt-3 block select-all break-all rounded-lg bg-white p-3 font-mono text-sm text-gray-900 dark:bg-gray-900 dark:text-gray-100">{{ $this->revealedKey }}</code>
                </div>

                <x-filament::icon-button
                    icon="heroicon-o-x-mark"
                    color="gray"
                    wire:click="dismissRevealedKey"
                    :label="__('panel.api_keys.reveal.dismiss')"
                />
            </div>
        </div>
    @endif

    <x-filament::section :heading="__('panel.api_keys.title')" :description="__('panel.api_keys.subtitle')">
        <div class="overflow-x-auto">
            <table class="w-full text-start text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="px-3 py-2 text-start">{{ __('panel.api_keys.field.name') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('panel.api_keys.field.key') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('panel.api_keys.field.scopes') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('panel.api_keys.field.last_used') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('panel.common.status') }}</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->getKeys() as $key)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="px-3 py-3 font-medium text-gray-950 dark:text-white">{{ $key->name }}</td>
                            <td class="px-3 py-3 font-mono text-xs text-gray-600 dark:text-gray-400" dir="ltr">
                                {{ $key->prefix }}…{{ $key->last_four }}
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex flex-wrap gap-1" dir="ltr">
                                    @forelse ($key->scopes ?? [] as $scope)
                                        <x-filament::badge color="gray" size="sm">{{ $scope }}</x-filament::badge>
                                    @empty
                                        <x-filament::badge color="warning" size="sm">{{ __('panel.api_keys.all_scopes') }}</x-filament::badge>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-3 py-3 text-gray-500 dark:text-gray-400">
                                {{ $key->last_used_at?->diffForHumans() ?? __('panel.api_keys.never_used') }}
                            </td>
                            <td class="px-3 py-3">
                                @if ($key->revoked_at !== null)
                                    <x-filament::badge color="danger" size="sm">{{ __('panel.api_keys.revoked') }}</x-filament::badge>
                                @elseif (! $key->isUsable())
                                    <x-filament::badge color="warning" size="sm">{{ __('panel.api_keys.expired') }}</x-filament::badge>
                                @else
                                    <x-filament::badge color="success" size="sm">{{ __('panel.common.active') }}</x-filament::badge>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-end">
                                @if ($key->revoked_at === null)
                                    <x-filament::button
                                        size="sm"
                                        color="danger"
                                        outlined
                                        wire:click="revoke({{ $key->getKey() }})"
                                        wire:confirm="{{ __('panel.api_keys.confirm_revoke') }}"
                                    >
                                        {{ __('panel.api_keys.action.revoke') }}
                                    </x-filament::button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-6 text-center text-gray-500 dark:text-gray-400">
                                {{ __('panel.api_keys.empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>

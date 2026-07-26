<x-filament-panels::page>
    @php($modules = $this->getModules())
    @php($canToggle = $this->canToggle())
    @php($plan = $this->planSlug())

    <x-filament::section :heading="__('panel.modules.title')" :description="__('panel.modules.subtitle', ['plan' => $plan ?? '—'])">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            @foreach ($modules as $module)
                <div class="flex items-start justify-between gap-4 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-lg">{{ $module['icon'] }}</span>
                            <span class="font-medium text-gray-950 dark:text-white">{{ $module['name'] }}</span>

                            @if ($module['is_beta'])
                                <x-filament::badge color="warning" size="sm">beta</x-filament::badge>
                            @endif
                        </div>

                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('panel.modules.requires_plan', ['plan' => $module['requires_plan']]) }}
                            · {{ __('panel.modules.phase', ['phase' => $module['phase']]) }}
                            · {{ count($module['question_types']) }} {{ __('panel.questions.plural') }}
                        </p>

                        @unless ($module['is_available'])
                            <p class="mt-1 text-xs text-gray-400">{{ __('panel.modules.not_shipped') }}</p>
                        @endunless

                        @unless ($module['allowed'])
                            <p class="mt-1 text-xs text-warning-600 dark:text-warning-400">
                                {{ __('panel.modules.plan_locked') }}
                            </p>
                        @endunless
                    </div>

                    <x-filament::button
                        :color="$module['enabled'] ? 'danger' : 'success'"
                        size="sm"
                        :disabled="! $canToggle || ! $module['is_available'] || (! $module['enabled'] && ! $module['allowed'])"
                        wire:click="toggle('{{ $module['key'] }}', {{ $module['enabled'] ? 'false' : 'true' }})"
                    >
                        {{ $module['enabled'] ? __('panel.modules.disable') : __('panel.modules.enable') }}
                    </x-filament::button>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>

<x-filament-panels::page>
    @unless ($this->planAllowsCustomDomain())
        <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm text-warning-700 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-400">
            {{ __('panel.domains.plan_locked') }}
        </div>
    @endunless

    <x-filament::section :heading="__('panel.domains.title')" :description="__('panel.domains.subtitle')">
        <div class="space-y-4">
            @foreach ($this->getDomains() as $domain)
                <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-3">
                            <span dir="ltr" class="font-mono font-medium text-gray-950 dark:text-white">{{ $domain->hostname }}</span>

                            @if ($domain->is_primary)
                                <x-filament::badge color="info" size="sm">{{ __('panel.domains.primary') }}</x-filament::badge>
                            @endif

                            <x-filament::badge :color="$domain->type->value === 'custom' ? 'gray' : 'success'" size="sm">
                                {{ __('panel.domains.type.'.$domain->type->value) }}
                            </x-filament::badge>

                            @if ($domain->isVerified())
                                <x-filament::badge color="success" size="sm">{{ __('panel.domains.verified') }}</x-filament::badge>
                            @else
                                <x-filament::badge color="warning" size="sm">{{ __('panel.domains.unverified') }}</x-filament::badge>
                            @endif

                            <x-filament::badge
                                :color="match ($domain->ssl_status->value) {
                                    'issued' => 'success',
                                    'failed' => 'danger',
                                    default => 'gray',
                                }"
                                size="sm"
                            >
                                SSL: {{ __('panel.domains.ssl.'.$domain->ssl_status->value) }}
                            </x-filament::badge>
                        </div>

                        <div class="flex items-center gap-2">
                            @unless ($domain->isVerified())
                                <x-filament::button size="sm" wire:click="verify({{ $domain->getKey() }})">
                                    {{ __('panel.domains.action.verify') }}
                                </x-filament::button>
                            @endunless

                            @if (! $domain->is_primary && $domain->type->value === 'custom')
                                <x-filament::button
                                    size="sm"
                                    color="danger"
                                    outlined
                                    wire:click="remove({{ $domain->getKey() }})"
                                    wire:confirm="{{ __('panel.domains.confirm_remove') }}"
                                >
                                    {{ __('panel.domains.action.remove') }}
                                </x-filament::button>
                            @endif
                        </div>
                    </div>

                    @if (! $domain->isVerified() && filled($domain->verification_token))
                        <div class="mt-4 rounded-lg bg-gray-50 p-3 text-xs dark:bg-white/5">
                            <p class="mb-2 font-medium text-gray-700 dark:text-gray-300">
                                {{ __('panel.domains.instructions.title') }}
                            </p>
                            <ol class="list-inside list-decimal space-y-1 text-gray-600 dark:text-gray-400">
                                <li>{{ __('panel.domains.instructions.step_record') }}</li>
                                <li>{{ __('panel.domains.instructions.step_wait') }}</li>
                                <li>{{ __('panel.domains.instructions.step_verify') }}</li>
                            </ol>

                            <dl class="mt-3 space-y-1 font-mono" dir="ltr">
                                <div>
                                    <dt class="inline text-gray-500">TXT&nbsp;</dt>
                                    <dd class="inline select-all text-gray-800 dark:text-gray-200">{{ $domain->dnsVerificationRecord() }}</dd>
                                </div>
                                <div>
                                    <dt class="inline text-gray-500">value&nbsp;</dt>
                                    <dd class="inline select-all text-gray-800 dark:text-gray-200">{{ $domain->verification_token }}</dd>
                                </div>
                            </dl>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>

<x-filament-panels::page>
    @php($subscription = $this->getSubscription())
    @php($plan = $this->getPlan())

    <x-filament::section :heading="__('panel.billing.current_plan')">
        <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-4">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('panel.plans.singular') }}</dt>
                <dd class="font-medium text-gray-950 dark:text-white">{{ $plan?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('panel.common.status') }}</dt>
                <dd>
                    @if ($subscription)
                        <x-filament::badge :color="$subscription->isLive() ? 'success' : 'danger'">
                            {{ $subscription->status->label() }}
                        </x-filament::badge>
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('panel.billing.cycle') }}</dt>
                <dd class="text-gray-950 dark:text-white">{{ $subscription?->billing_cycle?->label() ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('panel.billing.renews_at') }}</dt>
                <dd class="text-gray-950 dark:text-white">{{ $subscription?->current_period_end ?? '—' }}</dd>
            </div>
        </dl>
    </x-filament::section>

    <x-filament::section :heading="__('panel.billing.usage')">
        <div class="space-y-4">
            @foreach ($this->getQuotas() as $metric => $quota)
                @php($ratio = $quota->ratio())
                <div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-700 dark:text-gray-200">{{ $quota->metric()?->label() ?? $metric }}</span>
                        <span class="tabular-nums text-gray-600 dark:text-gray-300">
                            {{ number_format($quota->used()) }} /
                            {{ $quota->isUnlimited() ? '∞' : number_format((int) $quota->limit()) }}
                        </span>
                    </div>
                    <div class="mt-1 h-2 w-full rounded bg-gray-100 dark:bg-white/10">
                        <div
                            @class([
                                'h-2 rounded',
                                'bg-success-500' => $ratio === null || $ratio < 0.8,
                                'bg-warning-500' => $ratio !== null && $ratio >= 0.8 && $ratio < 0.95,
                                'bg-danger-500' => $ratio !== null && $ratio >= 0.95,
                            ])
                            style="width: {{ $ratio === null ? 2 : min(100, (int) round($ratio * 100)) }}%"
                        ></div>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('panel.billing.invoices')">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 text-start">{{ __('panel.billing.number') }}</th>
                        <th class="py-2 text-start">{{ __('panel.common.status') }}</th>
                        <th class="py-2 text-end">{{ __('panel.billing.total') }}</th>
                        <th class="py-2 text-start">{{ __('panel.billing.issued_at') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse ($this->getInvoices() as $invoice)
                        <tr>
                            <td class="py-2 font-mono text-gray-950 dark:text-white">{{ $invoice->number }}</td>
                            <td class="py-2">{{ $invoice->status }}</td>
                            <td class="py-2 text-end tabular-nums">{{ $invoice->totalMoney()->format() }}</td>
                            <td class="py-2">{{ $invoice->issued_at ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-center text-gray-500">{{ __('panel.billing.no_invoices') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('panel.billing.payments')">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 text-start">{{ __('panel.billing.gateway') }}</th>
                        <th class="py-2 text-start">{{ __('panel.common.status') }}</th>
                        <th class="py-2 text-end">{{ __('panel.billing.amount') }}</th>
                        <th class="py-2 text-start">{{ __('panel.billing.paid_at') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse ($this->getPayments() as $payment)
                        <tr>
                            <td class="py-2">{{ $payment->gateway->value }}</td>
                            <td class="py-2">{{ $payment->status->label() }}</td>
                            <td class="py-2 text-end tabular-nums">{{ $payment->money()->format() }}</td>
                            <td class="py-2">{{ $payment->paid_at ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-center text-gray-500">{{ __('panel.billing.no_payments') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>

<x-filament-panels::page>
    @php($ticket = $this->getRecord())
    @php($messages = $this->getMessages())

    <x-filament::section>
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <x-filament::badge :color="$ticket->status->color()">
                {{ $ticket->status->label() }}
            </x-filament::badge>

            <x-filament::badge color="gray">
                {{ $ticket->priority->label() }}
            </x-filament::badge>

            <x-filament::badge color="gray">
                {{ $ticket->source->label() }}
            </x-filament::badge>

            <span class="text-gray-500 dark:text-gray-400">
                {{ __('panel.tickets.field.student') }}:
                {{ $ticket->student ? trim($ticket->student->first_name.' '.$ticket->student->last_name) : '—' }}
            </span>

            <span class="text-gray-500 dark:text-gray-400">
                {{ __('panel.tickets.field.assignee') }}:
                {{ $ticket->assignee?->name ?? __('panel.tickets.unassigned') }}
            </span>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('panel.tickets.thread')">
        <div class="space-y-4">
            @forelse ($messages as $message)
                <div @class([
                    'rounded-xl border p-4',
                    'border-primary-200 bg-primary-50 dark:border-primary-500/30 dark:bg-primary-500/10' => $message->isFromStaff() && ! $message->is_internal,
                    'border-warning-300 bg-warning-50 dark:border-warning-500/30 dark:bg-warning-500/10' => $message->is_internal,
                    'border-gray-200 dark:border-white/10' => ! $message->isFromStaff() && ! $message->is_internal,
                ])>
                    <div class="mb-2 flex items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <span class="flex items-center gap-2">
                            <span class="font-medium text-gray-700 dark:text-gray-300">
                                {{ $message->sender_type->label() }}
                            </span>

                            @if ($message->is_internal)
                                <x-filament::badge color="warning" size="sm">
                                    {{ __('support.ticket.internal_note') }}
                                </x-filament::badge>
                            @endif
                        </span>

                        <span>{{ $message->created_at?->diffForHumans() }}</span>
                    </div>

                    <p class="whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-200">{{ $message->content }}</p>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('panel.tickets.empty_thread') }}</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>

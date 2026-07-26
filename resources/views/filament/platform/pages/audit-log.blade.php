<x-filament-panels::page>
    @if (! $this->isAvailable())
        <x-filament::section :heading="__('panel.audit.unavailable_title')">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ __('panel.audit.unavailable_body') }}
            </p>
        </x-filament::section>
    @else
        @php($entries = $this->getEntries())

        <x-filament::section>
            <div class="flex flex-wrap gap-4">
                <select wire:model.live="action" class="fi-input rounded-lg border-gray-300 text-sm dark:bg-white/5">
                    <option value="">{{ __('panel.audit.all_actions') }}</option>
                    @foreach ($this->getActionOptions() as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>

                <select wire:model.live="academyId" class="fi-input rounded-lg border-gray-300 text-sm dark:bg-white/5">
                    <option value="">{{ __('panel.audit.all_academies') }}</option>
                    @foreach ($this->getAcademyOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 text-start">{{ __('panel.audit.when') }}</th>
                            <th class="py-2 text-start">{{ __('panel.audit.action') }}</th>
                            <th class="py-2 text-start">{{ __('panel.audit.actor') }}</th>
                            <th class="py-2 text-start">{{ __('panel.audit.subject') }}</th>
                            <th class="py-2 text-start">{{ __('panel.audit.ip') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse ($entries as $entry)
                            <tr>
                                <td class="py-2 whitespace-nowrap">{{ $entry->created_at }}</td>
                                <td class="py-2 font-medium text-gray-950 dark:text-white">{{ $entry->action }}</td>
                                <td class="py-2">{{ $entry->actor_id ?? '—' }}</td>
                                <td class="py-2">
                                    {{ class_basename((string) ($entry->subject_type ?? '')) }}
                                    {{ $entry->subject_id ? '#' . $entry->subject_id : '' }}
                                </td>
                                <td class="py-2">{{ $entry->ip ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-4 text-center text-gray-500">{{ __('panel.audit.empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($entries !== null)
                <div class="pt-4">{{ $entries->links() }}</div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>

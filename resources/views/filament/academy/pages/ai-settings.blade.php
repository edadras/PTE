<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex gap-3">
            <x-filament-panels::form.actions :actions="$this->getCachedFormActions()" />
        </div>
    </form>
</x-filament-panels::page>

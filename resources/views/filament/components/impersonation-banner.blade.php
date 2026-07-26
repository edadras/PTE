@php
    $impersonation = \App\Filament\Support\Impersonation::payload();
@endphp

@if ($impersonation !== null)
    <div
        style="position: sticky; top: 0; z-index: 60; background: #b91c1c; color: #fff; padding: 0.5rem 1rem; display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; justify-content: center; font-size: 0.875rem; font-weight: 600;"
        role="alert"
    >
        <span>
            {{ __('panel.impersonation.banner', [
                'actor' => $impersonation['actor_name'],
                'minutes' => \App\Filament\Support\Impersonation::minutesRemaining(),
            ]) }}
        </span>

        <a
            href="{{ route('filament.panel.impersonation.stop') }}"
            style="text-decoration: underline;"
        >{{ __('panel.impersonation.stop') }}</a>
    </div>
@endif

@php
    /** @var array<string, string> $palette */
    $palette = \App\Filament\Support\BrandContext::palette();
    $font = \App\Filament\Support\BrandContext::fontFamily();
    $mode = \App\Filament\Support\BrandContext::darkModePreference();
@endphp

<style>
    :root {
        --brand-primary: {{ $palette['primary'] }};
        --brand-secondary: {{ $palette['secondary'] }};
        --brand-accent: {{ $palette['accent'] }};
        --brand-success: {{ $palette['success'] }};
        --brand-danger: {{ $palette['danger'] }};
        --brand-font: '{{ $font }}';
    }

    .fi-body {
        font-family: var(--brand-font), ui-sans-serif, system-ui, sans-serif;
    }
</style>

@if ($mode !== 'auto')
    {{-- The academy's brand decides the theme; `auto` leaves the staff toggle alone. --}}
    <script>
        try { localStorage.setItem('theme', @json($mode)); } catch (e) {}
    </script>
@endif

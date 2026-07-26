{{--
    Rendered by InvoiceGenerator::render(). Self-contained on purpose: the same
    markup is printed to PDF by a headless browser, where an external stylesheet
    would not resolve.
--}}
@php
    use App\Domain\Commerce\Support\Money;

    $rtl = in_array(app()->getLocale(), ['fa', 'ar'], true);
    $money = static fn (int $minor): string => (new Money($minor, $data->currency))->format();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('billing.invoice.title') }} {{ $data->number }}</title>
    <style>
        body { font-family: Tahoma, "DejaVu Sans", sans-serif; font-size: 13px; color: #1f2933; margin: 32px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #6b7280; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; }
        .meta td { padding: 2px 8px 2px 0; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 16px; }
        table.lines th, table.lines td { border-bottom: 1px solid #e5e7eb; padding: 8px 6px; text-align: {{ $rtl ? 'right' : 'left' }}; }
        table.lines th { background: #f9fafb; font-weight: 600; }
        .num { text-align: {{ $rtl ? 'left' : 'right' }}; white-space: nowrap; }
        .totals { margin-top: 16px; width: 100%; }
        .totals td { padding: 4px 6px; }
        .totals .label { text-align: {{ $rtl ? 'left' : 'right' }}; color: #6b7280; }
        .totals .grand { font-size: 15px; font-weight: 700; border-top: 2px solid #1f2933; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; background: #ecfdf5; color: #047857; font-size: 12px; }
        footer { margin-top: 32px; font-size: 11px; color: #9ca3af; }
    </style>
</head>
<body>
<div class="header">
    <div>
        <h1>{{ __('billing.invoice.title') }}</h1>
        <div class="muted">{{ config('pte.platform.name') }}</div>
    </div>
    <table class="meta">
        <tr>
            <td class="muted">{{ __('billing.invoice.number') }}</td>
            <td><strong>{{ $data->number }}</strong></td>
        </tr>
        <tr>
            <td class="muted">{{ __('billing.invoice.issued_at') }}</td>
            <td>{{ $data->issuedAt?->format('Y-m-d') }}</td>
        </tr>
        @if ($data->dueAt)
            <tr>
                <td class="muted">{{ __('billing.invoice.due_at') }}</td>
                <td>{{ $data->dueAt->format('Y-m-d') }}</td>
            </tr>
        @endif
        <tr>
            <td class="muted">{{ __('billing.invoice.status') }}</td>
            <td>{{ $invoice->status }}</td>
        </tr>
    </table>
</div>

@if ($data->billingDetails !== [])
    <div>
        <div class="muted">{{ __('billing.invoice.billed_to') }}</div>
        @foreach ($data->billingDetails as $line)
            <div>{{ is_scalar($line) ? $line : json_encode($line, JSON_UNESCAPED_UNICODE) }}</div>
        @endforeach
    </div>
@endif

<table class="lines">
    <thead>
    <tr>
        <th>{{ __('billing.invoice.description') }}</th>
        <th>{{ __('billing.invoice.period') }}</th>
        <th class="num">{{ __('billing.invoice.quantity') }}</th>
        <th class="num">{{ __('billing.invoice.unit_price') }}</th>
        <th class="num">{{ __('billing.invoice.line_total') }}</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($data->lines as $line)
        <tr>
            <td>{{ $line->description }}</td>
            <td class="muted">{{ $line->periodLabel }}</td>
            <td class="num">{{ $line->quantity }}</td>
            <td class="num">{{ $money($line->unitPrice) }}</td>
            <td class="num">{{ $money($line->total) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr>
        <td class="label">{{ __('billing.invoice.subtotal') }}</td>
        <td class="num">{{ $money($data->subtotal) }}</td>
    </tr>
    <tr>
        <td class="label">{{ __('billing.invoice.tax', ['rate' => rtrim(rtrim(number_format($data->taxRateBasisPoints / 100, 2), '0'), '.')]) }}</td>
        <td class="num">{{ $money($data->tax) }}</td>
    </tr>
    <tr class="grand">
        <td class="label">{{ __('billing.invoice.total') }}</td>
        <td class="num">{{ $money($data->total) }}</td>
    </tr>
</table>

@if ($invoice->status === \App\Domain\Commerce\Models\Invoice::STATUS_PAID)
    <p><span class="badge">{{ __('billing.invoice.paid_notice') }}</span></p>
@endif

<footer>{{ __('billing.invoice.footer') }}</footer>
</body>
</html>

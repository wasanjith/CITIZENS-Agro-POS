{{--
    Invoice content shared by the 80 mm counter invoice, the A4 invoice and the printing test.
    Expects: $language ('si' | 'en' | 'si+en'), $shop, $receipt, $invoice.
--}}
@php
    $primary = $language === 'en' ? 'en' : 'si';
    $bilingual = $language === 'si+en';
    $t = fn (string $key) => __('receipt.'.$key, [], $primary).($bilingual ? ' / '.__('receipt.'.$key, [], 'en') : '');
    $money = fn (float $amount) => number_format($amount, 2);
    $qty = fn (float $value) => rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    $isCash = $invoice['is_cash'] ?? true;
@endphp

<div class="center">
    <div class="shop-name">{{ $primary === 'si' ? $shop['name_si'] : $shop['name_en'] }}</div>
    @if ($bilingual)
        <div>{{ $shop['name_en'] }}</div>
    @endif
    @if ($address = $primary === 'si' ? ($shop['address_si'] ?: $shop['address_en']) : $shop['address_en'])
        <div class="muted">{{ $address }}</div>
    @endif
    @if ($shop['phone'])
        <div class="muted">{{ $shop['phone'] }}</div>
    @endif
</div>

@if (! empty($invoice['is_sample']))
    <div class="center" style="margin-top: 2mm"><span class="badge">{{ $t('sample') }}</span></div>
@endif
@if (! empty($invoice['is_copy']))
    <div class="center" style="margin-top: 2mm"><span class="badge">{{ __('receipt.copy', [], 'si') }} / {{ __('receipt.copy', [], 'en') }}</span></div>
@endif
@if (! empty($invoice['is_void']))
    <div class="center" style="margin-top: 2mm"><span class="badge">{{ $t('void') }}</span></div>
@endif
@if (! empty($invoice['is_credit']))
    <div class="center" style="margin-top: 2mm"><span class="badge">{{ __('receipt.credit_invoice', [], 'si') }} / {{ __('receipt.credit_invoice', [], 'en') }}</span></div>
@endif

<hr class="rule">

<div class="row"><span>{{ $t('invoice_no') }}</span><span class="num">{{ $invoice['number'] }}</span></div>
<div class="row"><span>{{ $t('date') }}</span><span class="num">{{ $invoice['date']->format('Y-m-d H:i') }}</span></div>
<div class="row"><span>{{ $t('counter') }}</span><span class="num">{{ $invoice['counter'] }}</span></div>
@if ($receipt['show_staff_name'])
    <div class="row"><span>{{ $t('staff') }}</span><span>{{ $invoice['staff'] }}</span></div>
@endif
@if (! empty($invoice['customer']))
    <div class="row"><span>{{ $t('customer') }}</span><span>{{ $primary === 'si' ? ($invoice['customer']['name_si'] ?: $invoice['customer']['name']) : $invoice['customer']['name'] }}</span></div>
    <div class="row muted"><span>{{ $invoice['customer']['code'] }}</span><span class="num">{{ $invoice['customer']['phone'] }}</span></div>
@endif

<hr class="rule">

@foreach ($invoice['items'] as $item)
    <div class="item-name">{{ $primary === 'si' ? ($item['name_si'] ?: $item['name']) : $item['name'] }}</div>
    <div class="row item-line">
        <span class="num">{{ $qty($item['qty']) }} {{ $primary === 'si' ? $item['unit_si'] : $item['unit'] }} × {{ $money($item['price']) }}</span>
        <span class="num">{{ $money($item['total'] + ($item['discount'] ?? 0)) }}</span>
    </div>
    @if (($item['discount'] ?? 0) > 0)
        <div class="row item-line muted">
            <span>{{ $t('discount') }}</span>
            <span class="num">-{{ $money($item['discount']) }}</span>
        </div>
    @endif
@endforeach

<hr class="rule">

<div class="totals">
    <div class="row"><span>{{ $t('subtotal') }}</span><span class="num">{{ $money($invoice['subtotal']) }}</span></div>
    @if ($invoice['discount'] > 0)
        <div class="row"><span>{{ $t('discount') }}</span><span class="num">-{{ $money($invoice['discount']) }}</span></div>
    @endif
    <div class="row grand-total"><span>{{ $t('total') }}</span><span class="num">{{ $money($invoice['total']) }}</span></div>
    @if ($isCash)
        <div class="row"><span>{{ $t('paid_cash') }}</span><span class="num">{{ $money($invoice['tendered']) }}</span></div>
        <div class="row grand-total"><span>{{ $t('balance') }}</span><span class="num">{{ $money($invoice['balance']) }}</span></div>
    @elseif (! empty($invoice['is_credit']))
        <div class="row"><span>{{ $t('paid') }}</span><span class="num">{{ $money(0) }}</span></div>
        <div class="row grand-total"><span>{{ $t('to_pay') }} ({{ $t('credit') }})</span><span class="num">{{ $money($invoice['total']) }}</span></div>
        @if (! empty($invoice['due_date']))
            <div class="row"><span>{{ $t('due_date') }}</span><span class="num">{{ $invoice['due_date']->format('Y-m-d') }}</span></div>
        @endif
    @else
        <div class="row"><span>{{ $t('paid') }} ({{ $t('method_'.$invoice['method']) }})</span><span class="num">{{ $money($invoice['tendered']) }}</span></div>
    @endif
</div>

<hr class="rule">

<div class="center muted">
    <div>{{ $t('items_count') }}: {{ count($invoice['items']) }}</div>
    <div style="margin-top: 1mm">{{ $primary === 'si' ? $receipt['footer_si'] : $receipt['footer_en'] }}</div>
    @if ($bilingual && $receipt['footer_en'])
        <div>{{ $receipt['footer_en'] }}</div>
    @endif
</div>

{{--
    80 mm return receipt on the main printer.
    Expects: $return (SaleReturn with sale, customer, lines.saleItem, creator), $language, $shop, $isCopy.
--}}
@extends('layouts.print', ['htmlLang' => $language === 'en' ? 'en' : 'si'])

@php
    $primary = $language === 'en' ? 'en' : 'si';
    $bilingual = $language === 'si+en';
    $t = fn (string $key) => __('receipt.'.$key, [], $primary).($bilingual ? ' / '.__('receipt.'.$key, [], 'en') : '');
    $money = fn ($amount) => number_format((float) $amount, 2);
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
@endphp

@section('title', $return->number)

@section('content')
    <div class="center">
        <div class="shop-name">{{ $primary === 'si' ? $shop['name_si'] : $shop['name_en'] }}</div>
        @if ($shop['phone'])<div class="muted">{{ $shop['phone'] }}</div>@endif
        <div class="grand-total" style="margin-top: 1mm">{{ $t('return_receipt') }}</div>
        @if ($isCopy)
            <div><span class="badge">{{ __('receipt.copy', [], 'si') }} / {{ __('receipt.copy', [], 'en') }}</span></div>
        @endif
    </div>

    <hr class="rule">
    <div class="row"><span>{{ $t('return_no') }}</span><span class="num">{{ $return->number }}</span></div>
    <div class="row"><span>{{ $t('date') }}</span><span class="num">{{ $return->created_at->format('Y-m-d H:i') }}</span></div>
    <div class="row"><span>{{ $t('original_invoice') }}</span><span class="num">{{ $return->sale->invoice_no }}</span></div>
    @if ($return->customer)
        <div class="row"><span>{{ $t('customer') }}</span><span>{{ $primary === 'si' ? ($return->customer->name_si ?: $return->customer->name) : $return->customer->name }}</span></div>
    @endif
    <div class="row"><span>{{ $t('reason') }}</span><span>{{ $return->reason }}</span></div>

    <hr class="rule">
    @foreach ($return->lines as $line)
        @php $item = $line->saleItem; @endphp
        <div class="item-name">{{ $primary === 'si' ? ($item->name_si_snapshot ?: $item->name_snapshot) : $item->name_snapshot }}@if (! $line->restock) ({{ $t('damaged') }})@endif</div>
        <div class="row item-line">
            <span class="num">{{ $qty($line->qty) }} {{ $primary === 'si' ? ($item->unit_si_snapshot ?: $item->unit_snapshot) : $item->unit_snapshot }}</span>
            <span class="num">{{ $money($line->amount) }}</span>
        </div>
    @endforeach

    <hr class="rule">
    <div class="row grand-total"><span>{{ $t('refund_total') }}</span><span class="num">{{ $money($return->total) }}</span></div>
    <div class="row"><span>{{ $t('refund_'.$return->refund_method->value) }}</span><span></span></div>

    <hr class="rule">
    <div style="margin-top: 8mm">{{ $t('signature') }}: ....................................</div>
    <div class="center muted" style="margin-top: 2mm">{{ $return->creator->name }} · {{ now()->format('Y-m-d H:i') }}</div>
@endsection

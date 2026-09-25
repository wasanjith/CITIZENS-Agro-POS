{{--
    80 mm quotation, printed on the counter printer that made it.
    Expects: $quotation (with lines, customer, creator, terminal), $language, $shop, $isCopy.
--}}
@extends('layouts.print', ['htmlLang' => $language === 'en' ? 'en' : 'si'])

@php
    $primary = $language === 'en' ? 'en' : 'si';
    $bilingual = $language === 'si+en';
    $t = fn (string $key) => __('receipt.'.$key, [], $primary).($bilingual ? ' / '.__('receipt.'.$key, [], 'en') : '');
    $money = fn ($amount) => number_format((float) $amount, 2);
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    $discount = (float) $quotation->line_discount_total + (float) $quotation->bill_discount;
@endphp

@section('title', $quotation->number)

@section('content')
    <div class="center">
        <div class="shop-name">{{ $primary === 'si' ? $shop['name_si'] : $shop['name_en'] }}</div>
        @if ($address = $primary === 'si' ? ($shop['address_si'] ?: $shop['address_en']) : $shop['address_en'])<div class="muted">{{ $address }}</div>@endif
        @if ($shop['phone'])<div class="muted">{{ $shop['phone'] }}</div>@endif
        <div class="grand-total" style="margin-top: 1mm">{{ $t('quotation') }}</div>
        @if ($isCopy)
            <div><span class="badge">{{ __('receipt.copy', [], 'si') }} / {{ __('receipt.copy', [], 'en') }}</span></div>
        @endif
    </div>

    <hr class="rule">
    <div class="row"><span>{{ $t('quotation_no') }}</span><span class="num">{{ $quotation->number }}</span></div>
    <div class="row"><span>{{ $t('date') }}</span><span class="num">{{ $quotation->created_at->format('Y-m-d H:i') }}</span></div>
    <div class="row"><span>{{ $t('valid_until') }}</span><span class="num">{{ $quotation->valid_until->format('Y-m-d') }}</span></div>
    @if ($name = $quotation->customerLabel())
        <div class="row"><span>{{ $t('customer') }}</span><span>{{ $name }}</span></div>
    @endif
    <div class="row"><span>{{ $t('staff') }}</span><span>{{ $quotation->creator->name }}</span></div>

    <hr class="rule">
    @foreach ($quotation->lines as $line)
        <div class="item-name">{{ $primary === 'si' ? ($line->name_si_snapshot ?: $line->name_snapshot) : $line->name_snapshot }}</div>
        <div class="row item-line">
            <span class="num">{{ $qty($line->qty) }} {{ $primary === 'si' ? ($line->unit_si_snapshot ?: $line->unit_snapshot) : $line->unit_snapshot }} × {{ $money($line->unit_price) }}</span>
            <span class="num">{{ $money((float) $line->line_total + (float) $line->discount_amount) }}</span>
        </div>
        @if ((float) $line->discount_amount > 0)
            <div class="row item-line muted"><span>{{ $t('discount') }}</span><span class="num">-{{ $money($line->discount_amount) }}</span></div>
        @endif
    @endforeach

    <hr class="rule">
    <div class="totals">
        <div class="row"><span>{{ $t('subtotal') }}</span><span class="num">{{ $money($quotation->subtotal) }}</span></div>
        @if ($discount > 0)
            <div class="row"><span>{{ $t('discount') }}</span><span class="num">-{{ $money($discount) }}</span></div>
        @endif
        <div class="row grand-total"><span>{{ $t('total') }}</span><span class="num">{{ $money($quotation->total) }}</span></div>
    </div>

    <hr class="rule">
    <div class="center muted">{{ $t('quotation_note') }}</div>
    @if ($quotation->note)<div class="center muted">{{ $quotation->note }}</div>@endif
@endsection

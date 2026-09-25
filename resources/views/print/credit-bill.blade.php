{{--
    80 mm credit bill, printed on the main printer when a credit sale is settled. The
    customer signs it, the owner signs and stamps the shop seal, and the shop keeps it.
    Expects: $sale (with items, customer, settledBy), $accountBalance, $language, $shop, $isCopy.
--}}
@extends('layouts.print', ['htmlLang' => $language === 'en' ? 'en' : 'si'])

@php
    $primary = $language === 'en' ? 'en' : 'si';
    $bilingual = $language === 'si+en';
    $t = fn (string $key) => __('receipt.'.$key, [], $primary).($bilingual ? ' / '.__('receipt.'.$key, [], 'en') : '');
    $money = fn ($amount) => number_format((float) $amount, 2);
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    $customer = $sale->customer;
    $discount = (float) $sale->line_discount_total + (float) $sale->bill_discount;
@endphp

@section('title', $t('credit_bill').' '.$sale->invoice_no)

@section('content')
    <div class="center">
        <div class="shop-name">{{ $primary === 'si' ? $shop['name_si'] : $shop['name_en'] }}</div>
        @if ($address = $primary === 'si' ? ($shop['address_si'] ?: $shop['address_en']) : $shop['address_en'])<div class="muted">{{ $address }}</div>@endif
        @if ($shop['phone'])<div class="muted">{{ $shop['phone'] }}</div>@endif
        <div class="grand-total" style="margin-top: 1mm">{{ __('receipt.credit_bill', [], 'si') }} / {{ __('receipt.credit_bill', [], 'en') }}</div>
        <div><span class="badge">{{ $isCopy ? __('receipt.copy', [], 'si').' / '.__('receipt.copy', [], 'en') : $t('shop_copy') }}</span></div>
    </div>

    <hr class="rule">
    <div class="row"><span>{{ $t('invoice_no') }}</span><span class="num">{{ $sale->invoice_no }}</span></div>
    <div class="row"><span>{{ $t('date') }}</span><span class="num">{{ ($sale->settled_at ?? $sale->invoiced_at)->format('Y-m-d H:i') }}</span></div>

    <hr class="rule">
    <div class="item-name">{{ $t('customer') }}</div>
    <div class="grand-total">{{ $primary === 'si' ? ($customer->name_si ?: $customer->name) : $customer->name }}</div>
    @if ($bilingual && $customer->name_si)<div>{{ $customer->name }}</div>@endif
    <div class="row"><span>{{ $t('customer_code') }}</span><span class="num">{{ $customer->code }}</span></div>
    @if ($customer->phone)<div class="row"><span>{{ $t('phone') }}</span><span class="num">{{ $customer->phone }}</span></div>@endif
    @if ($customer->nic)<div class="row"><span>{{ $t('nic') }}</span><span class="num">{{ $customer->nic }}</span></div>@endif
    @if ($customer->address || $customer->area)<div class="muted">{{ trim(($customer->address ?? '').' '.($customer->area ?? '')) }}</div>@endif

    <hr class="rule">
    @foreach ($sale->items as $item)
        <div class="item-name">{{ $primary === 'si' ? ($item->name_si_snapshot ?: $item->name_snapshot) : $item->name_snapshot }}</div>
        <div class="row item-line">
            <span class="num">{{ $qty($item->qty) }} {{ $primary === 'si' ? ($item->unit_si_snapshot ?: $item->unit_snapshot) : $item->unit_snapshot }} × {{ $money($item->unit_price) }}</span>
            <span class="num">{{ $money($item->line_total) }}</span>
        </div>
    @endforeach

    <hr class="rule">
    <div class="totals">
        @if ($discount > 0)
            <div class="row"><span>{{ $t('discount') }}</span><span class="num">-{{ $money($discount) }}</span></div>
        @endif
        <div class="row grand-total"><span>{{ $t('to_pay') }}</span><span class="num">{{ $money($sale->total) }}</span></div>
        <div class="row"><span>{{ $t('due_date') }}</span><span class="num">{{ $sale->due_date?->format('Y-m-d') }}</span></div>
        <div class="row"><span>{{ $t('credit_days') }}</span><span class="num">{{ $customer->credit_days }}</span></div>
        <div class="row"><span>{{ $t('account_balance') }}</span><span class="num">{{ $money($accountBalance) }}</span></div>
    </div>

    <hr class="rule">
    <div>{{ $t('credit_agreement') }}</div>

    <div style="margin-top: 12mm">....................................................</div>
    <div>{{ $t('customer_signature') }}</div>

    <div style="margin-top: 12mm">....................................................</div>
    <div>{{ $t('owner_signature') }}</div>

    <div class="center" style="margin-top: 4mm; border: 1px dashed #000; height: 26mm; display: flex; align-items: center; justify-content: center;">
        <span class="muted">{{ $t('shop_seal') }}</span>
    </div>

    <div class="center muted" style="margin-top: 2mm">{{ $sale->settledBy?->name }} · {{ now()->format('Y-m-d H:i') }}</div>
@endsection

{{--
    80 mm customer payment receipt on the main printer.
    Expects: $payment (CustomerPayment with customer, allocations.sale, receivedBy), $balanceBefore, $balanceNow, $language, $shop, $isCopy.
--}}
@extends('layouts.print', ['htmlLang' => $language === 'en' ? 'en' : 'si'])

@php
    $primary = $language === 'en' ? 'en' : 'si';
    $bilingual = $language === 'si+en';
    $t = fn (string $key) => __('receipt.'.$key, [], $primary).($bilingual ? ' / '.__('receipt.'.$key, [], 'en') : '');
    $money = fn ($amount) => number_format((float) $amount, 2);
    $customer = $payment->customer;
    $advance = (float) $payment->amount - (float) $payment->allocations->sum('amount');
@endphp

@section('title', $payment->number)

@section('content')
    <div class="center">
        <div class="shop-name">{{ $primary === 'si' ? $shop['name_si'] : $shop['name_en'] }}</div>
        @if ($shop['phone'])<div class="muted">{{ $shop['phone'] }}</div>@endif
        <div class="grand-total" style="margin-top: 1mm">{{ $t('payment_receipt') }}</div>
        @if ($isCopy)
            <div><span class="badge">{{ __('receipt.copy', [], 'si') }} / {{ __('receipt.copy', [], 'en') }}</span></div>
        @endif
    </div>

    <hr class="rule">
    <div class="row"><span>{{ $t('receipt_no') }}</span><span class="num">{{ $payment->number }}</span></div>
    <div class="row"><span>{{ $t('date') }}</span><span class="num">{{ $payment->created_at->format('Y-m-d H:i') }}</span></div>
    <div class="row"><span>{{ $t('customer') }}</span><span>{{ $primary === 'si' ? ($customer->name_si ?: $customer->name) : $customer->name }}</span></div>
    <div class="row muted"><span>{{ $customer->code }}</span><span class="num">{{ $customer->phone }}</span></div>
    <div class="row"><span>{{ $t('cashier') }}</span><span>{{ $payment->receivedBy->name }}</span></div>

    <hr class="rule">
    <div class="row grand-total"><span>{{ $t('amount_received') }}</span><span class="num">{{ $money($payment->amount) }}</span></div>
    <div class="row"><span>{{ $t('method_'.$payment->method->value) }}</span><span class="num">{{ $payment->reference }}</span></div>

    @if ($payment->allocations->isNotEmpty())
        <hr class="rule">
        <div class="item-name">{{ $t('applied_to') }}</div>
        @foreach ($payment->allocations as $allocation)
            <div class="row"><span class="num">{{ $allocation->sale->invoice_no }}</span><span class="num">{{ $money($allocation->amount) }}</span></div>
        @endforeach
    @endif
    @if ($advance > 0.004)
        <div class="row"><span>{{ $t('advance') }}</span><span class="num">{{ $money($advance) }}</span></div>
    @endif

    <hr class="rule">
    <div class="row"><span>{{ $t('previous_balance') }}</span><span class="num">{{ $money($balanceBefore) }}</span></div>
    <div class="row grand-total"><span>{{ $t('balance_now') }}</span><span class="num">{{ $money($balanceNow) }}</span></div>

    <hr class="rule">
    <div style="margin-top: 8mm">{{ $t('signature') }}: ....................................</div>
    <div class="center muted" style="margin-top: 2mm">{{ __('receipt.thank_you', [], $primary) }}</div>
@endsection

{{--
    80 mm drawer report on the main printer: Z report (end of day, all sessions of the day),
    handover slip (one session handed over) or X report (open drawer, preview).
    Expects: $kind ('z' | 'handover' | 'x'), $report (DrawerCalculator::zReport), $language, $shop.
--}}
@extends('layouts.print', ['htmlLang' => $language === 'en' ? 'en' : 'si'])

@php
    $primary = $language === 'en' ? 'en' : 'si';
    $bilingual = $language === 'si+en';
    $t = fn (string $key) => __('receipt.'.$key, [], $primary).($bilingual ? ' / '.__('receipt.'.$key, [], 'en') : '');
    $money = fn ($amount) => number_format((float) $amount, 2);
    $title = match ($kind) { 'z' => $t('z_report'), 'handover' => $t('handover_slip'), default => 'X' };
@endphp

@section('title', $title)

@section('content')
    <div class="center">
        <div class="shop-name">{{ $primary === 'si' ? $shop['name_si'] : $shop['name_en'] }}</div>
        <div class="grand-total">{{ $title }}</div>
        @if ($report['is_open'])
            <div><span class="badge">{{ $primary === 'si' ? 'විවෘතයි' : 'OPEN' }}</span></div>
        @endif
    </div>

    <hr class="rule">
    <div class="row"><span>{{ $t('from') }}</span><span class="num">{{ $report['from']->format('Y-m-d H:i') }}</span></div>
    <div class="row"><span>{{ $t('to') }}</span><span class="num">{{ $report['to']->format('Y-m-d H:i') }}</span></div>

    @foreach ($report['sessions'] as $session)
        <hr class="rule">
        <div class="item-name">{{ $t('holder') }}: {{ $session['holder'] }}</div>
        <div class="row muted"><span>{{ $session['opened_at']->format('H:i') }} – {{ $session['closed_at']?->format('H:i') ?? '…' }}</span><span>{{ $session['close_reason'] }}</span></div>
        <div class="row"><span>{{ $t('opening_float') }}</span><span class="num">{{ $money($session['summary']['opening_float']) }}</span></div>
        <div class="row"><span>{{ $t('cash_sales') }}</span><span class="num">{{ $money($session['summary']['cash_sales']) }}</span></div>
        @foreach ($session['summary']['movements'] as $type => $movement)
            @if ((float) $movement['amount'] > 0)
                <div class="row"><span>{{ $t($type) }}</span><span class="num">{{ $movement['sign'] > 0 ? '' : '-' }}{{ $money($movement['amount']) }}</span></div>
            @endif
        @endforeach
        <div class="row"><span>{{ $t('expected_cash') }}</span><span class="num">{{ $money($session['expected_cash'] ?? $session['summary']['expected_cash']) }}</span></div>
        @if ($session['counted_cash'] !== null)
            <div class="row"><span>{{ $t('counted_cash') }}</span><span class="num">{{ $money($session['counted_cash']) }}</span></div>
            <div class="row item-name"><span>{{ $t('variance') }}</span><span class="num">{{ $money($session['variance']) }}</span></div>
        @endif
        @if ($session['handed_to'])
            <div class="row"><span>{{ $t('handed_to') }}</span><span>{{ $session['handed_to'] }}</span></div>
        @endif
    @endforeach

    <hr class="rule">
    <div class="item-name">{{ $t('sales_by_counter') }}</div>
    @forelse ($report['per_counter'] as $row)
        <div class="row"><span>{{ $row['terminal'] }} ({{ $row['count'] }})</span><span class="num">{{ $money($row['total']) }}</span></div>
    @empty
        <div class="muted">—</div>
    @endforelse
    <div class="row grand-total"><span>{{ $t('sales_total') }} ({{ $report['sales_count'] }})</span><span class="num">{{ $money($report['sales_total']) }}</span></div>

    <hr class="rule">
    <div class="item-name">{{ $t('payments_by_method') }}</div>
    @forelse ($report['by_method'] as $method => $row)
        <div class="row"><span>{{ $t('method_'.$method) }} ({{ $row['count'] }})</span><span class="num">{{ $money($row['amount']) }}</span></div>
    @empty
        <div class="muted">—</div>
    @endforelse

    <hr class="rule">
    <div class="item-name">{{ $t('voids') }}: {{ $report['void_count'] }}</div>
    @foreach ($report['voids'] as $row)
        <div class="row"><span>{{ $row['terminal'] }} ({{ $row['count'] }})</span><span class="num">{{ $money($row['total']) }}</span></div>
        <div class="muted" style="padding-left: 3mm">{{ implode(', ', $row['numbers']) }}</div>
    @endforeach

    @if ($kind === 'z' && count($report['sessions']) > 1)
        <hr class="rule">
        <div class="row item-name"><span>{{ $t('variance') }} ({{ count($report['sessions']) }})</span><span class="num">{{ $money($report['total_variance']) }}</span></div>
    @endif

    @if ($kind === 'handover')
        <hr class="rule">
        <div style="margin-top: 8mm">{{ $t('signature') }} 1: ....................................</div>
        <div style="margin-top: 8mm">{{ $t('signature') }} 2: ....................................</div>
    @endif

    <hr class="rule">
    <div class="center muted">{{ now()->format('Y-m-d H:i') }}</div>
@endsection

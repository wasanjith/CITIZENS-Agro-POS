{{-- Printer test page (Printers → Test print): Sinhala conjuncts, an 80 mm ruler, terminal and time. --}}
@extends('layouts.print', ['htmlLang' => 'si'])

@section('title', 'Test print')

@section('content')
    <div class="center">
        <div class="shop-name">{{ __('receipt.test_print', [], 'si') }}</div>
        <div>{{ __('receipt.test_print', [], 'en') }}</div>
    </div>

    <hr class="rule">

    <div class="row"><span>Terminal</span><span>{{ $terminal->displayName() }} ({{ $terminal->code }})</span></div>
    <div class="row"><span>Printer</span><span>{{ $printer?->name ?? '—' }}</span></div>
    <div class="row"><span>Windows</span><span>{{ $printer?->windows_name ?: 'default printer' }}</span></div>
    <div class="row"><span>Paper</span><span class="num">{{ $paperWidth }} mm · {{ $printer?->dpi ?? 203 }} dpi</span></div>
    <div class="row"><span>{{ __('receipt.date', [], 'si') }}</span><span class="num">{{ now()->format('Y-m-d H:i:s') }}</span></div>

    <hr class="rule">

    {{-- Conjuncts and vowel signs that break without proper shaping. --}}
    <div class="item-name">ක්‍ෂ · ශ්‍රී · ප්‍ර · ක්‍ර · ද්‍ර · ත්‍ර · ස්ව</div>
    <div>යූරියා පොහොර · වී බීජ · කෘමිනාශක</div>
    <div>ත්‍රිත්ව සුපර් පොස්පේට් · බයිසිකල් ටියුබ්</div>
    <div class="grand-total num">රු. 1,234,567.89</div>

    <hr class="rule">

    {{-- 80 mm ruler: each block is 10 mm; the last one should end at the paper edge margin. --}}
    <div style="display: flex; width: 100%; border: 1px solid #000; height: 4mm; margin-top: 1mm">
        @for ($i = 0; $i < 7; $i++)
            <div style="flex: 0 0 10mm; border-right: 1px solid #000; font-size: 7pt; text-align: right; padding-right: 0.5mm">{{ ($i + 1) * 10 }}</div>
        @endfor
    </div>
    <div class="center muted" style="margin-top: 1mm">72 mm printable width</div>

    <hr class="rule">
    <div class="center muted">{{ __('receipt.thank_you', [], 'si') }} · {{ __('receipt.thank_you', [], 'en') }}</div>
@endsection

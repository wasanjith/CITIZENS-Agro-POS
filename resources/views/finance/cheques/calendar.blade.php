@extends('layouts.app')

@section('title', 'Cheque calendar')

@section('content')
    <x-ui.page-header title="Cheque calendar" description="Cheques not yet cleared, on the day they can be banked. Green = received (deposit it), amber = issued (keep the money in the bank).">
        <x-ui.button variant="secondary" :href="route('finance.cheques.calendar', ['month' => $month->copy()->subMonth()->format('Y-m')])">← {{ $month->copy()->subMonth()->format('M') }}</x-ui.button>
        <x-ui.button variant="secondary" :href="route('finance.cheques.calendar')">This month</x-ui.button>
        <x-ui.button variant="secondary" :href="route('finance.cheques.calendar', ['month' => $month->copy()->addMonth()->format('Y-m')])">{{ $month->copy()->addMonth()->format('M') }} →</x-ui.button>
    </x-ui.page-header>

    @if ($overdue->isNotEmpty())
        <x-ui.alert type="warning" class="mb-4" title="Dated before this view and still open">
            @foreach ($overdue as $cheque)
                <a href="{{ route('finance.cheques.show', $cheque) }}" class="hover:underline">{{ $cheque->cheque_date->format('Y-m-d') }} · {{ $cheque->number }} · {{ $cheque->partyLabel() }} · Rs. {{ number_format((float) $cheque->amount, 2) }}</a>@if (! $loop->last)<br>@endif
            @endforeach
        </x-ui.alert>
    @endif

    <h2 class="mb-2 text-lg font-semibold text-gray-900">{{ $month->format('F Y') }}</h2>

    <div class="overflow-x-auto">
        <div class="grid min-w-[56rem] grid-cols-7 gap-px overflow-hidden rounded-lg bg-gray-200 ring-1 ring-gray-200">
            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day)
                <div class="bg-gray-50 px-2 py-1 text-xs font-semibold uppercase text-gray-600">{{ $day }}</div>
            @endforeach
            @for ($day = $start->copy(); $day->lte($end); $day->addDay())
                @php $onDay = $cheques[$day->toDateString()] ?? collect(); @endphp
                <div @class(['min-h-24 bg-white p-1.5', 'bg-gray-50 text-gray-400' => ! $day->isSameMonth($month), 'ring-2 ring-inset ring-brand-500' => $day->isToday()])>
                    <p class="text-xs font-medium">{{ $day->day }}</p>
                    @foreach ($onDay as $cheque)
                        <a href="{{ route('finance.cheques.show', $cheque) }}" @class([
                            'mt-1 block truncate rounded px-1.5 py-0.5 text-xs',
                            'bg-brand-50 text-brand-800 hover:bg-brand-100' => $cheque->direction === \App\Domain\Finance\Enums\ChequeDirection::Received,
                            'bg-amber-50 text-amber-900 hover:bg-amber-100' => $cheque->direction === \App\Domain\Finance\Enums\ChequeDirection::Issued,
                        ]) title="{{ $cheque->partyLabel() }} · {{ $cheque->status->label() }}">
                            {{ number_format((float) $cheque->amount, 0) }} · {{ $cheque->partyLabel() }}
                        </a>
                    @endforeach
                </div>
            @endfor
        </div>
    </div>
@endsection

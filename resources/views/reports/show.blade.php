{{-- Generic report page: filter bar → tiles → table with totals. Expects $report, $input, $filters, $columns, $result, $rows, $hiddenRows. --}}
@extends('layouts.app')

@section('title', $report->title())

@php
    $query = $input->toQuery();
    $hasTotals = $rows !== [] && collect($columns)->contains('total', true);
    $presets = [
        'Today' => [today(), today()],
        'Yesterday' => [today()->subDay(), today()->subDay()],
        'This week' => [today()->startOfWeek(), today()],
        'This month' => [today()->startOfMonth(), today()],
        'Last month' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()],
        'This year' => [today()->startOfYear(), today()],
    ];
@endphp

@section('content')
    <x-ui.page-header :title="$report->title()" :description="$report->subtitle($input)">
        <x-ui.button variant="ghost" :href="route('reports.index')" class="print:hidden">All reports</x-ui.button>
        <x-ui.button variant="secondary" :href="route('reports.export', ['report' => $report->key(), 'format' => 'xlsx', ...$query])" class="print:hidden">Excel</x-ui.button>
        <x-ui.button variant="secondary" :href="route('reports.export', ['report' => $report->key(), 'format' => 'pdf', ...$query])" class="print:hidden">PDF</x-ui.button>
    </x-ui.page-header>

    <form method="GET" action="{{ route('reports.show', $report->key()) }}" class="mb-4 space-y-3 print:hidden">
        <div class="flex flex-wrap items-end gap-3">
            @if ($report->usesPeriod())
                <x-ui.date-input name="from" label="From" :value="$input->from->toDateString()" />
                <x-ui.date-input name="to" label="To" :value="$input->to->toDateString()" />
            @endif

            @foreach ($filters as $filter)
                @switch($filter->type)
                    @case('select')
                        <x-ui.select :name="$filter->name" :label="$filter->label" :options="$filter->options" :value="$input->get($filter->name)" :placeholder="$filter->placeholder" class="min-w-40" />
                        @break
                    @case('number')
                        <x-ui.input :name="$filter->name" :label="$filter->label" type="number" min="0" max="3650" :value="$input->get($filter->name)" class="w-40" />
                        @break
                    @case('checkbox')
                        <x-ui.checkbox :name="$filter->name" :label="$filter->label" :checked="$input->flag($filter->name)" class="pb-2" />
                        @break
                    @default
                        <x-ui.input :name="$filter->name" :label="$filter->label" :value="$input->get($filter->name)" :placeholder="$filter->placeholder" class="w-56" />
                @endswitch
            @endforeach

            <x-ui.button type="submit">Show</x-ui.button>
        </div>

        @if ($report->usesPeriod())
            <div class="flex flex-wrap gap-2 text-xs">
                @foreach ($presets as $label => [$from, $to])
                    @php $active = $input->from->isSameDay($from) && $input->to->isSameDay($to); @endphp
                    <a
                        href="{{ route('reports.show', ['report' => $report->key(), ...$query, 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}"
                        @class([
                            'rounded-full px-3 py-1 ring-1 ring-inset',
                            'bg-brand-600 text-white ring-brand-600' => $active,
                            'text-gray-700 ring-gray-300 hover:bg-gray-50' => ! $active,
                        ])
                    >{{ $label }}</a>
                @endforeach
            </div>
        @endif
    </form>

    @if ($result->tiles)
        <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($result->tiles as $label => $value)
                <x-ui.stat-tile :label="$label" :value="$value" />
            @endforeach
        </div>
    @endif

    @if ($rows === [])
        <x-ui.empty-state title="Nothing to show" description="Nothing matches these filters. Try a longer period." />
    @else
        <x-ui.table>
            <x-slot:head>
                @foreach ($columns as $column)
                    <th @class(['whitespace-nowrap', 'text-right' => $column->isNumeric()])>{{ $column->label }}</th>
                @endforeach
            </x-slot:head>
            @foreach ($rows as $row)
                <tr @class(['text-red-700' => ! empty($row['_alert'])])>
                    @foreach ($columns as $column)
                        <td @class(['text-right tabular whitespace-nowrap' => $column->isNumeric(), 'whitespace-nowrap' => in_array($column->type, ['date', 'datetime'], true)])>
                            @if ($loop->first && ! empty($row['_url']))
                                <a href="{{ $row['_url'] }}" class="font-medium text-brand-700 hover:underline">{{ $column->format($row[$column->key] ?? null) ?: '—' }}</a>
                            @else
                                {{ $column->format($row[$column->key] ?? null) }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
            @if ($hasTotals)
                <tr class="bg-gray-50 font-semibold">
                    @foreach ($columns as $column)
                        <td @class(['text-right tabular whitespace-nowrap' => $column->isNumeric()])>
                            {{ $column->total ? $column->format($column->sum($result->rows)) : ($loop->first ? 'Total' : '') }}
                        </td>
                    @endforeach
                </tr>
            @endif
        </x-ui.table>

        @if ($hiddenRows > 0)
            <p class="mt-3 text-sm text-amber-800">{{ number_format($hiddenRows) }} more lines are not shown here. Download the Excel file for all of them.</p>
        @endif
    @endif

    @foreach ($result->notes as $note)
        <p class="mt-3 text-xs text-gray-500">{{ $note }}</p>
    @endforeach
@endsection

@extends('layouts.app')

@section('title', 'Reports')

@section('content')
    <x-ui.page-header title="Reports" description="Choose a report. Every report can be filtered and downloaded as Excel or PDF." />

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ($menu as $section)
            <x-ui.card :title="$section['group']->label()">
                <ul class="-my-2 divide-y divide-gray-100">
                    @foreach ($section['reports'] as $report)
                        <li class="py-2">
                            <a href="{{ route('reports.show', $report->key()) }}" class="font-medium text-brand-700 hover:underline">{{ $report->title() }}</a>
                            <p class="text-sm text-gray-500">{{ $report->description() }}</p>
                        </li>
                    @endforeach
                    @foreach ($section['links'] as $link)
                        <li class="py-2">
                            <a href="{{ route($link['route']) }}" class="font-medium text-brand-700 hover:underline">{{ $link['title'] }}</a>
                            <span class="ml-1 text-xs text-gray-400">(own page)</span>
                            <p class="text-sm text-gray-500">{{ $link['description'] }}</p>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endforeach
    </div>
@endsection

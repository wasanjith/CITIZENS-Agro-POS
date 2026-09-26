@extends('layouts.app')

@section('title', 'Shifts, holidays & leave')

@section('content')
    <x-ui.page-header title="Shifts, holidays & leave types" description="Working hours decide late, early leave and overtime. Holidays are not working days." />

    @if ($errors->any())
        <x-ui.alert type="error" class="mb-4">{{ $errors->first() }}</x-ui.alert>
    @endif

    <div class="grid gap-6">
        <x-ui.card title="Shifts" description="Employees without their own shift use the default one.">
            <div class="space-y-4">
                @foreach ($shifts as $shift)
                    <form method="POST" action="{{ route('hr.shifts.update', $shift) }}" class="grid items-end gap-3 border-b border-gray-100 pb-4 sm:grid-cols-6">
                        @csrf
                        @method('PUT')
                        @include('hr.setup.shift-fields', ['shift' => $shift])
                        <div class="flex items-center gap-2 sm:col-span-6">
                            <x-ui.button type="submit" size="sm">Save</x-ui.button>
                            <span class="text-xs text-gray-500">{{ $shift->employees_count }} {{ str('employee')->plural($shift->employees_count) }} on this shift{{ $shift->is_default ? ' · default for everyone else' : '' }}</span>
                        </div>
                    </form>
                @endforeach

                <details>
                    <summary class="cursor-pointer text-sm font-medium text-brand-700">Add a shift</summary>
                    <form method="POST" action="{{ route('hr.shifts.store') }}" class="mt-3 grid items-end gap-3 sm:grid-cols-6">
                        @csrf
                        @include('hr.setup.shift-fields', ['shift' => new \App\Domain\HR\Models\Shift(['start_time' => '08:00', 'end_time' => '18:00', 'grace_minutes' => 10, 'working_days' => [1, 2, 3, 4, 5, 6], 'is_default' => false])])
                        <div class="sm:col-span-6"><x-ui.button type="submit" size="sm">Add shift</x-ui.button></div>
                    </form>
                </details>
            </div>
        </x-ui.card>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.card title="Holidays {{ $year }}" description="Poya days and public holidays. Work on a holiday is overtime.">
                <x-slot:actions>
                    <x-ui.button size="sm" variant="ghost" :href="route('hr.setup.index', ['year' => $year - 1])">&larr; {{ $year - 1 }}</x-ui.button>
                    <x-ui.button size="sm" variant="ghost" :href="route('hr.setup.index', ['year' => $year + 1])">{{ $year + 1 }} &rarr;</x-ui.button>
                </x-slot:actions>

                <form method="POST" action="{{ route('hr.holidays.store') }}" class="mb-4 flex flex-wrap items-end gap-3">
                    @csrf
                    <x-ui.date-input name="date" label="Day" required />
                    <x-ui.input name="name" label="Name" required maxlength="100" placeholder="Vesak Full Moon Poya Day" class="flex-1" />
                    <x-ui.button type="submit">Add</x-ui.button>
                </form>

                @forelse ($holidays as $holiday)
                    <div class="flex items-center justify-between border-t border-gray-100 py-1.5 text-sm">
                        <span><span class="tabular text-gray-500">{{ $holiday->date->format('D, j M') }}</span> · {{ $holiday->name }}</span>
                        <form method="POST" action="{{ route('hr.holidays.destroy', $holiday) }}">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" size="sm" variant="ghost" aria-label="Remove {{ $holiday->name }}">Remove</x-ui.button>
                        </form>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No holidays entered for {{ $year }}.</p>
                @endforelse
            </x-ui.card>

            <x-ui.card title="Leave types" description="Days per year: 0 means no limit.">
                <div class="space-y-3">
                    @foreach ($leaveTypes as $type)
                        <form method="POST" action="{{ route('hr.leave-types.update', $type) }}" class="flex flex-wrap items-end gap-3 border-b border-gray-100 pb-3">
                            @csrf
                            @method('PUT')
                            <x-ui.input name="name" :value="$type->name" required maxlength="60" class="flex-1" aria-label="Name" />
                            <x-ui.input name="days_per_year" :value="$type->days_per_year" type="number" step="0.5" min="0" class="w-24" aria-label="Days per year" />
                            <label class="flex items-center gap-1 text-sm"><input type="checkbox" name="is_paid" value="1" @checked($type->is_paid) class="rounded border-gray-300 text-brand-600"> Paid</label>
                            <label class="flex items-center gap-1 text-sm"><input type="checkbox" name="is_active" value="1" @checked($type->is_active) class="rounded border-gray-300 text-brand-600"> In use</label>
                            <x-ui.button type="submit" size="sm" variant="secondary">Save</x-ui.button>
                        </form>
                    @endforeach
                    <form method="POST" action="{{ route('hr.leave-types.store') }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <x-ui.input name="name" label="New leave type" required maxlength="60" class="flex-1" />
                        <x-ui.input name="days_per_year" label="Days / year" type="number" step="0.5" min="0" value="0" class="w-24" />
                        <label class="flex items-center gap-1 pb-2 text-sm"><input type="checkbox" name="is_paid" value="1" checked class="rounded border-gray-300 text-brand-600"> Paid</label>
                        <x-ui.button type="submit">Add</x-ui.button>
                    </form>
                </div>
            </x-ui.card>
        </div>
    </div>
@endsection

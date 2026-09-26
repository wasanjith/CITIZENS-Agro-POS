{{--
    Clock in / clock out for the signed-in employee. $style: 'app' (top bar menu) or 'pos' (dark POS bar).
--}}
@php
    $clockEmployee = auth()->user()?->can('hr.attendance.self')
        ? \App\Domain\HR\Models\Employee::query()->active()->where('user_id', auth()->id())->first()
        : null;
    $clockRow = $clockEmployee ? app(\App\Domain\HR\Services\AttendanceService::class)->forDay($clockEmployee, today()) : null;
    $clockTerminal = app(\App\Domain\Identity\Support\CurrentTerminal::class)->get();
@endphp

@if ($clockEmployee)
    @if ($clockRow?->clock_in && ! $clockRow->clock_out)
        <form method="POST" action="{{ route('hr.clock-out') }}" x-data @submit="if (! confirm('Clock out now? You cannot clock in again today.')) $event.preventDefault()">
            @csrf
            @if (($style ?? 'app') === 'pos')
                <button type="submit" class="rounded bg-amber-500 px-2 py-1 text-xs font-semibold text-gray-900 hover:bg-amber-400" title="Clocked in at {{ $clockRow->clock_in->format('H:i') }}">Clock out</button>
            @else
                <button type="submit" class="block w-full px-4 py-2 text-left hover:bg-gray-50">Clock out <span class="text-xs text-gray-500">(in at {{ $clockRow->clock_in->format('H:i') }})</span></button>
            @endif
        </form>
    @elseif (! $clockRow?->clock_in && $clockTerminal)
        <form method="POST" action="{{ route('hr.clock-in') }}">
            @csrf
            @if (($style ?? 'app') === 'pos')
                <button type="submit" class="rounded bg-white/15 px-2 py-1 text-xs font-semibold hover:bg-white/25">Clock in</button>
            @else
                <button type="submit" class="block w-full px-4 py-2 text-left hover:bg-gray-50">Clock in</button>
            @endif
        </form>
    @endif
@endif

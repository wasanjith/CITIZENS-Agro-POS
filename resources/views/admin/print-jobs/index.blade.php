@extends('layouts.app')

@section('title', 'Print log')

@section('content')
    <x-ui.page-header title="Print log" description="Every print and reprint on the thermal printers. A job without “printed” was not confirmed by the terminal.">
        <x-ui.button variant="secondary" :href="route('admin.printers.index')">Printers</x-ui.button>
    </x-ui.page-header>

    <form method="GET" class="mb-4 grid gap-3 rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200 sm:grid-cols-6">
        <x-ui.select name="terminal_id" label="Terminal" :options="$terminals->mapWithKeys(fn ($t) => [$t->id => $t->displayName()])->all()" placeholder="All" :value="request('terminal_id')" />
        <x-ui.select name="user_id" label="User" :options="$users->pluck('name', 'id')->all()" placeholder="All" :value="request('user_id')" />
        <x-ui.select name="type" label="Document" :options="$types" placeholder="All" :value="request('type')" />
        <x-ui.date-input name="date" label="Date" :value="request('date')" />
        <label class="flex items-end gap-2 pb-2 text-sm"><input type="checkbox" name="copies" value="1" @checked(request('copies')) class="rounded border-gray-300 text-brand-600"> Copies only</label>
        <div class="flex items-end gap-2"><x-ui.button type="submit">Filter</x-ui.button><x-ui.button variant="ghost" :href="route('admin.print-jobs.index')">Reset</x-ui.button></div>
    </form>

    <x-ui.table>
        <x-slot:head>
            <th>Time</th>
            <th>Document</th>
            <th>Terminal · printer</th>
            <th>User</th>
            <th>Copy</th>
            <th>Printed</th>
        </x-slot:head>
        @forelse ($jobs as $job)
            <tr>
                <td class="tabular">{{ $job->created_at->format('Y-m-d H:i:s') }}</td>
                <td>
                    {{ $job->document_type->label() }}
                    @if ($job->document_type === \App\Domain\Sales\Enums\PrintDocumentType::Invoice && isset($invoiceNumbers[$job->document_id]))
                        · <a class="text-brand-700 hover:underline" href="{{ route('sales.show', $job->document_id) }}">{{ $invoiceNumbers[$job->document_id] }}</a>
                    @endif
                </td>
                <td>{{ $job->terminal?->displayName() ?? '—' }} <span class="text-gray-500">· {{ $job->printer?->name ?? 'no printer' }}</span></td>
                <td>{{ $job->user?->name ?? '—' }}</td>
                <td>@if ($job->is_copy)<x-ui.badge color="amber">COPY</x-ui.badge>@endif</td>
                <td>
                    @if ($job->printed_at)
                        <span class="tabular text-brand-700">{{ $job->printed_at->format('H:i:s') }}</span>
                    @else
                        <x-ui.badge color="red">Not confirmed</x-ui.badge>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-gray-500">Nothing printed yet.</td></tr>
        @endforelse
    </x-ui.table>

    <div class="mt-4">{{ $jobs->links() }}</div>
@endsection

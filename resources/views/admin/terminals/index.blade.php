@extends('layouts.app')

@section('title', 'Terminals')

@section('content')
    <x-ui.page-header title="Terminals" description="The main cashier PC and the counter PCs. Each PC is registered once from its own browser.">
        <x-ui.button :href="route('admin.terminals.create')">Add terminal</x-ui.button>
    </x-ui.page-header>

    <x-ui.alert type="info" class="mb-6">
        To register a PC: sign in on that PC with a username and password, open this page and choose
        <strong>Register this device</strong> next to the right terminal.
        @if ($currentTerminal)
            This browser is registered as <strong>{{ $currentTerminal->displayName() }}</strong>.
        @else
            This browser is <strong>not registered</strong>.
        @endif
    </x-ui.alert>

    <x-ui.table>
        <x-slot:head>
            <th>Terminal</th>
            <th>Type</th>
            <th>Printer</th>
            <th>Device</th>
            <th>Last seen</th>
            <th><span class="sr-only">Actions</span></th>
        </x-slot:head>

        @foreach ($terminals as $terminal)
            <tr @class(['bg-brand-50/50' => $currentTerminal?->is($terminal)])>
                <td>
                    <p class="font-medium">{{ $terminal->name }}</p>
                    <p class="text-xs text-gray-500">{{ $terminal->code }} @unless ($terminal->is_active) · <span class="text-red-600">inactive</span> @endunless</p>
                </td>
                <td>
                    <x-ui.badge :color="$terminal->isMainCashier() ? 'purple' : 'blue'">
                        {{ $terminal->isCounter() ? 'Counter '.$terminal->counter_no : $terminal->type->label() }}
                    </x-ui.badge>
                </td>
                <td class="text-gray-600">
                    @if ($terminal->printer)
                        {{ $terminal->printer->name }}
                        @if ($terminal->printer->has_cash_drawer)
                            <x-ui.badge color="amber">drawer</x-ui.badge>
                        @endif
                    @else
                        <span class="text-amber-700">No printer</span>
                    @endif
                </td>
                <td>
                    @if ($terminal->isRegistered())
                        <x-ui.badge color="green">Registered</x-ui.badge>
                        <p class="mt-1 text-xs text-gray-500">{{ $terminal->registered_at?->format('Y-m-d H:i') }} by {{ $terminal->registeredBy?->name ?? '—' }}</p>
                    @else
                        <x-ui.badge color="gray">Not registered</x-ui.badge>
                    @endif
                </td>
                <td class="text-gray-600">{{ $terminal->last_seen_at?->diffForHumans() ?? '—' }}</td>
                <td class="whitespace-nowrap text-right [&>a]:ml-4 [&>button]:ml-4">
                    @if ($terminal->is_active && ! $currentTerminal?->is($terminal))
                        <x-ui.button variant="link" :href="route('admin.terminals.register', $terminal)">Register this device</x-ui.button>
                    @endif
                    @if ($terminal->isRegistered())
                        <x-ui.button variant="link" class="text-red-700" x-data x-on:click="$dispatch('open-modal', 'unregister-{{ $terminal->id }}')">Unregister</x-ui.button>
                        <x-ui.confirm-modal
                            name="unregister-{{ $terminal->id }}"
                            :action="route('admin.terminals.register.destroy', $terminal)"
                            method="DELETE"
                            title="Unregister {{ $terminal->name }}?"
                            confirm="Unregister"
                        >
                            The PC registered as {{ $terminal->name }} will stop working as a terminal until it is registered again.
                        </x-ui.confirm-modal>
                    @endif
                    <x-ui.button variant="link" :href="route('admin.terminals.edit', $terminal)">Edit</x-ui.button>
                </td>
            </tr>
        @endforeach
    </x-ui.table>
@endsection

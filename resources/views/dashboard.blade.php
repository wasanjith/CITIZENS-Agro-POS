@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <x-ui.page-header :title="'Welcome, '.$user->name" :description="now()->format('l, j F Y')" />

    @if ($user->isSuperAdmin() && ! $user->two_factor_confirmed_at)
        <x-ui.alert type="warning" title="Protect the owner account" class="mb-6">
            Two-factor authentication is not turned on. It is required before the dashboard is opened remotely from a phone.
            <a href="{{ route('account') }}" class="font-semibold underline">Turn it on</a>
        </x-ui.alert>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat-tile label="This device" :value="$terminal?->displayName() ?? 'Not registered'" :hint="$terminal ? $terminal->type->label() : 'PIN sign-in is disabled here'" />
        <x-ui.stat-tile label="Cashier authority" :value="$cashierHolder?->name ?? '—'" :hint="$activeDelegation ? 'Delegated until '.$activeDelegation->expires_at->format('H:i') : 'Owner'" />
        <x-ui.stat-tile label="Terminals registered" :value="$stats['registeredTerminals'].' / '.$stats['terminals']" :href="auth()->user()->can('admin.terminals.manage') ? route('admin.terminals.index') : null" />
        <x-ui.stat-tile label="Active users" :value="$stats['users']" :hint="$stats['printers'].' printers configured'" :href="auth()->user()->can('admin.users.manage') ? route('admin.users.index') : null" />
    </div>

    <x-ui.card title="Point of sale" class="mt-6">
        <div class="flex flex-wrap gap-2">
            @if ($terminal && $user->can('pos.sell'))
                <x-ui.button :href="route('pos.counter')">Billing screen</x-ui.button>
            @endif
            @if ($terminal?->isMainCashier() && $user->canAny(['pos.settle', 'drawer.manage']))
                <x-ui.button :href="route('pos.cashier')">Cashier</x-ui.button>
            @endif
            @can('pos.live_view')
                <x-ui.button variant="secondary" :href="route('admin.live-billing')">Live Billing</x-ui.button>
                <x-ui.button variant="secondary" :href="route('sales.index')">Invoices</x-ui.button>
            @endcan
            @can('drawer.handover')
                <x-ui.button variant="secondary" :href="route('admin.delegations.index')">Cashier authority</x-ui.button>
            @endcan
        </div>

        @if ($today !== null)
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <x-ui.stat-tile label="Settled today" :value="'Rs. '.number_format((float) $today['total'], 2)" :hint="$today['count'].' invoices'" />
                <x-ui.stat-tile label="Waiting for settlement" :value="$today['waiting']" />
                <x-ui.stat-tile label="Voids today" :value="$today['voids']" />
            </div>
        @endif
    </x-ui.card>
@endsection

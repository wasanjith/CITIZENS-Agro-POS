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

    <x-ui.card title="What's next" description="The system is being built in phases." class="mt-6">
        <ul class="list-inside list-disc space-y-1 text-sm text-gray-600">
            <li><strong>Phase 0 (now):</strong> users, roles, PIN sign-in, terminals, printers, settings, audit log, Sinhala printing test.</li>
            <li><strong>Phase 1:</strong> product catalogue and search.</li>
            <li><strong>Phase 2:</strong> stock, purchase orders and goods receiving.</li>
            <li><strong>Phase 3:</strong> counter billing, settlement at the main cashier, Live Billing and cashier handover.</li>
        </ul>
    </x-ui.card>
@endsection

@extends('layouts.app')

@section('title', 'Cashier authority')

@section('content')
    <x-ui.page-header title="Cashier authority" description="Who holds the drawer and settlement right now. Revoke a handover from anywhere; the Manager must then count and close the drawer.">
        @can('pos.live_view')
            <x-ui.button variant="secondary" :href="route('admin.live-billing')">Live Billing</x-ui.button>
        @endcan
        <x-ui.button variant="secondary" :href="route('admin.drawer-sessions.index')">Handover history</x-ui.button>
    </x-ui.page-header>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat-tile label="Cashier authority" :value="$holder?->name ?? '—'" />
        <x-ui.stat-tile label="Drawer" :value="$session ? 'Open · '.$session->holder->name : 'Closed'" :hint="$session ? 'Since '.$session->opened_at->format('H:i') : null" />
        <x-ui.stat-tile label="Active handovers" :value="$active->count()" />
    </div>

    <x-ui.card title="Active handovers" class="mt-6">
        @forelse ($active as $delegation)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 py-3 last:border-0">
                <div class="text-sm">
                    <p><span class="font-semibold">{{ $delegation->toUser->name }}</span> holds cashier authority from {{ $delegation->fromUser->name }}</p>
                    <p class="text-gray-500">Since {{ $delegation->starts_at->format('H:i') }} · ends {{ $delegation->expires_at->format('Y-m-d H:i') }} ({{ $delegation->expires_at->diffForHumans() }}) @if ($delegation->reason) · {{ $delegation->reason }} @endif</p>
                    <p class="mt-1 text-xs text-gray-500">{{ implode(', ', $delegation->permissions) }}</p>
                </div>
                <form method="POST" action="{{ route('admin.delegations.revoke', $delegation) }}" onsubmit="return confirm('Revoke the cashier authority of {{ e($delegation->toUser->name) }} now?')">
                    @csrf
                    <x-ui.button type="submit" variant="danger">Revoke now</x-ui.button>
                </form>
            </div>
        @empty
            <p class="text-sm text-gray-500">No handover is active. The owner holds cashier authority.</p>
        @endforelse
    </x-ui.card>

    <h2 class="mb-3 mt-8 text-base font-semibold">Recent handovers</h2>
    <x-ui.table>
        <x-slot:head>
            <th>From → to</th>
            <th>Started</th>
            <th>Ends</th>
            <th>Status</th>
            <th>Reason</th>
        </x-slot:head>
        @forelse ($recent as $delegation)
            <tr>
                <td>{{ $delegation->fromUser->name }} → <span class="font-medium">{{ $delegation->toUser->name }}</span></td>
                <td class="tabular">{{ $delegation->starts_at->format('Y-m-d H:i') }}</td>
                <td class="tabular">{{ $delegation->expires_at->format('H:i') }}</td>
                <td>
                    @if ($delegation->revoked_at)
                        <x-ui.badge color="gray">Ended {{ $delegation->revoked_at->format('H:i') }} by {{ $delegation->revokedBy?->name }}</x-ui.badge>
                    @elseif ($delegation->expires_at->isPast())
                        <x-ui.badge color="amber">Expired</x-ui.badge>
                    @else
                        <x-ui.badge color="green">Active</x-ui.badge>
                    @endif
                </td>
                <td class="text-gray-600">{{ $delegation->reason ?: '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-gray-500">No handovers yet.</td></tr>
        @endforelse
    </x-ui.table>
@endsection

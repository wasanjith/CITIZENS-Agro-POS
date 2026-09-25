@extends('layouts.pos')

@section('title', 'Hand over cashier')

@php
    $permissionLabels = [
        'pos.settle' => 'Settle invoices from the counters',
        'pos.live_view' => 'Live Billing (see the counters)',
        'pos.void' => 'Void invoices',
        'pos.refund' => 'Refunds',
        'pos.discount.override' => 'Discounts above the limit',
        'pos.approve_requests' => 'Approve counter requests',
        'drawer.manage' => 'Pay in / pay out / safe drop, close the day',
        'customers.credit.manage' => 'Receive customer credit payments',
    ];
    $chosen = old('permissions', $permissions);
@endphp

@section('content')
    <div class="mx-auto max-w-4xl p-4" x-data="{ counted: 0, managerCounted: @js(old('manager_counted', '')) }" @count-total="counted = $event.detail">
        <x-ui.page-header title="Hand over cashier authority" description="Count the drawer with the Manager. Invoices still waiting stay in the list and move to the Manager." />

        <form method="POST" action="{{ route('pos.handover.store') }}" class="space-y-4">
            @csrf

            <x-pos.denomination-count :denominations="$denominations" title="1. Owner counts the drawer" />

            <x-ui.card title="2. Who takes over, and until when">
                @if ($managers->isEmpty())
                    <x-ui.alert type="warning">No active Manager with a PIN. Set a PIN on the Manager's account first (Users).</x-ui.alert>
                @endif
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select name="manager_id" label="Manager" :options="$managers->pluck('name', 'id')->all()" :value="old('manager_id', $managers->first()?->id)" required />
                    <x-ui.input name="expires_at" type="datetime-local" label="Authority ends at" :value="old('expires_at', $defaultExpiry)" required hint="Default: today's closing time." />
                    <x-ui.input name="reason" label="Reason" class="sm:col-span-2" maxlength="255" placeholder="Owner at the bank, lunch …" />
                </div>

                <fieldset class="mt-4">
                    <legend class="text-sm font-medium text-gray-700">What the Manager may do</legend>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach ($permissions as $permission)
                            <label class="flex items-start gap-2 text-sm">
                                <input type="checkbox" name="permissions[]" value="{{ $permission }}" @checked(in_array($permission, $chosen, true)) @if ($permission === 'pos.settle') onclick="return false" @endif class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                <span>{{ $permissionLabels[$permission] ?? $permission }} @if ($permission === 'pos.settle')<span class="text-gray-400">(always)</span>@endif</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-gray-500">Bank accounts, payroll, users, settings and profit reports are never handed over.</p>
                    @error('permissions')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </fieldset>
            </x-ui.card>

            <x-ui.card title="3. Manager confirms the count and enters their PIN">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-ui.label for="manager_counted">Amount the Manager counted</x-ui.label>
                        <div class="mt-1 flex gap-2">
                            <input id="manager_counted" name="manager_counted" x-model="managerCounted" inputmode="decimal" autocomplete="off" class="block w-full rounded-md border-gray-300 text-right text-sm tabular" placeholder="0.00">
                            <x-ui.button variant="secondary" @click="managerCounted = counted.toFixed(2)">Same as owner</x-ui.button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Owner counted <strong class="tabular" x-text="'Rs. ' + counted.toLocaleString('en-LK', { minimumFractionDigits: 2 })"></strong>. A different amount means: count again together.</p>
                        @error('manager_counted')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <x-ui.input name="manager_pin" type="password" label="Manager's PIN" inputmode="numeric" maxlength="6" autocomplete="off" required />
                </div>
            </x-ui.card>

            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" :href="route('pos.cashier')">Cancel</x-ui.button>
                <x-ui.button type="submit" size="lg" :disabled="$managers->isEmpty()">Hand over and sign in the Manager</x-ui.button>
            </div>
        </form>
    </div>
@endsection

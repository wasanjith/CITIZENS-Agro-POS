@extends('layouts.app')

@section('title', 'Customers')

@section('content')
    <x-ui.page-header title="Customers" description="Farmers and regular customers, their credit and what they owe.">
        <x-ui.button variant="secondary" :href="route('customers.ageing')">Credit ageing</x-ui.button>
        @can('create', \App\Domain\Customers\Models\Customer::class)
            <x-ui.button :href="route('customers.create')">Add customer</x-ui.button>
        @endcan
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('customers.index')" placeholder="Name, phone, NIC, code or village" class="mb-4">
        <x-ui.select name="filter[area]" :options="$areas" :value="request('filter.area')" placeholder="Any village" />
        <x-ui.select name="filter[owing]" :options="['1' => 'Owes money']" :value="request('filter.owing')" placeholder="Any balance" />
        <x-ui.select name="filter[overdue]" :options="['1' => 'Overdue']" :value="request('filter.overdue')" placeholder="Due or not" />
        <x-ui.select name="filter[status]" :options="['active' => 'Active', 'inactive' => 'Inactive']" :value="request('filter.status')" placeholder="Any status" />
    </x-ui.filter-bar>

    @if ($customers->isEmpty())
        <x-ui.empty-state title="No customers found" description="Add farmers who buy on credit, or anyone who wants their purchases on record.">
            @can('create', \App\Domain\Customers\Models\Customer::class)
                <x-ui.button :href="route('customers.create')">Add customer</x-ui.button>
            @endcan
        </x-ui.empty-state>
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="code">Code</x-ui.th-sortable>
                <x-ui.th-sortable column="name" default="name">Name</x-ui.th-sortable>
                <th>Phone · village</th>
                <th>Price list</th>
                <th class="text-right">Credit limit</th>
                <th class="text-right">Balance</th>
                <th>Status</th>
            </x-slot:head>

            @foreach ($customers as $customer)
                @php $balance = (float) ($customer->debits ?? 0) - (float) ($customer->credits ?? 0); @endphp
                <tr>
                    <td class="font-mono text-xs">{{ $customer->code }}</td>
                    <td>
                        <a href="{{ route('customers.show', $customer) }}" class="font-medium text-brand-700 hover:underline">{{ $customer->name }}</a>
                        @if ($customer->name_si)<span class="block font-sinhala text-xs text-gray-500">{{ $customer->name_si }}</span>@endif
                    </td>
                    <td class="text-gray-600">{{ $customer->phone ?: '—' }} @if ($customer->area)<span class="block text-xs text-gray-500">{{ $customer->area }}</span>@endif</td>
                    <td class="text-gray-600">{{ $customer->priceList?->name ?? 'Default' }}</td>
                    <td class="text-right tabular">{{ number_format((float) $customer->credit_limit, 2) }}</td>
                    <td @class(['text-right tabular', 'font-semibold' => $balance > 0, 'text-brand-700' => $balance < 0])>{{ number_format($balance, 2) }}</td>
                    <td><x-ui.badge :color="$customer->is_active ? 'green' : 'red'">{{ $customer->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$customers" />
    @endif
@endsection

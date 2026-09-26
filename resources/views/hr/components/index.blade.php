@extends('layouts.app')

@section('title', 'Allowances & deductions')

@section('content')
    <x-ui.page-header title="Allowances & deductions" description="Paid or taken every month. Tick them on each employee's form; an employee can have their own amount." />

    @if ($errors->any())
        <x-ui.alert type="error" class="mb-4">{{ $errors->first() }}</x-ui.alert>
    @endif

    <x-ui.card title="Add" class="mb-6">
        <form method="POST" action="{{ route('hr.components.store') }}" class="flex flex-wrap items-end gap-3" x-data="{ type: 'allowance' }">
            @csrf
            <x-ui.input name="name" label="Name" required maxlength="80" placeholder="Transport allowance" class="min-w-56 flex-1" />
            <x-ui.select name="type" label="Kind" :options="$types" x-model="type" />
            <x-ui.select name="calc" label="Amount is" :options="$calcs" />
            <x-ui.input name="value" label="Rs. or %" required inputmode="decimal" class="w-28" />
            <label class="flex items-center gap-2 pb-2 text-sm" x-show="type === 'allowance'"><input type="checkbox" name="is_epf_applicable" value="1" class="rounded border-gray-300 text-brand-600"> EPF applies</label>
            <x-ui.button type="submit">Add</x-ui.button>
        </form>
        <p class="mt-2 text-xs text-gray-500">"EPF applies": the allowance is added to the earnings EPF and ETF are worked out on (e.g. a fixed attendance allowance). Leave it off for travel or meal allowances.</p>
    </x-ui.card>

    @if ($components->isEmpty())
        <x-ui.empty-state title="None yet" />
    @else
        <x-ui.table>
            <x-slot:head>
                <th>Name</th>
                <th>Kind</th>
                <th>Amount</th>
                <th>EPF</th>
                <th class="text-right">Employees</th>
                <th>In use</th>
                <th></th>
            </x-slot:head>
            @foreach ($components as $salaryComponent)
                <tr x-data="{ editing: false, type: @js($salaryComponent->type->value) }">
                    <td>
                        <span x-show="! editing">{{ $salaryComponent->name }}</span>
                        <form x-show="editing" x-cloak method="POST" action="{{ route('hr.components.update', $salaryComponent) }}" id="component-{{ $salaryComponent->id }}" class="flex flex-wrap items-center gap-2">
                            @csrf
                            @method('PUT')
                            <input type="text" name="name" value="{{ $salaryComponent->name }}" maxlength="80" required class="rounded-md border-gray-300 text-sm" aria-label="Name">
                            <select name="type" x-model="type" class="rounded-md border-gray-300 text-sm" aria-label="Kind">
                                @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                            <select name="calc" class="rounded-md border-gray-300 text-sm" aria-label="Amount is">
                                @foreach ($calcs as $value => $label)<option value="{{ $value }}" @selected($salaryComponent->calc->value === $value)>{{ $label }}</option>@endforeach
                            </select>
                            <input type="text" name="value" value="{{ $salaryComponent->value }}" inputmode="decimal" required class="w-28 rounded-md border-gray-300 text-sm" aria-label="Amount">
                            <label class="flex items-center gap-1 text-sm" x-show="type === 'allowance'"><input type="checkbox" name="is_epf_applicable" value="1" @checked($salaryComponent->is_epf_applicable) class="rounded border-gray-300 text-brand-600"> EPF</label>
                            <label class="flex items-center gap-1 text-sm"><input type="checkbox" name="is_active" value="1" @checked($salaryComponent->is_active) class="rounded border-gray-300 text-brand-600"> In use</label>
                        </form>
                    </td>
                    <td><x-ui.badge :color="$salaryComponent->type === \App\Domain\HR\Enums\SalaryComponentType::Allowance ? 'green' : 'amber'">{{ $salaryComponent->type->label() }}</x-ui.badge></td>
                    <td class="tabular">{{ $salaryComponent->valueLabel() }}</td>
                    <td>{{ $salaryComponent->is_epf_applicable ? 'Yes' : '' }}</td>
                    <td class="text-right tabular">{{ $salaryComponent->employees_count }}</td>
                    <td>{{ $salaryComponent->is_active ? 'Yes' : 'No' }}</td>
                    <td class="text-right">
                        <x-ui.button size="sm" variant="ghost" x-show="! editing" x-on:click="editing = true">Edit</x-ui.button>
                        <x-ui.button size="sm" type="submit" x-show="editing" x-cloak form="component-{{ $salaryComponent->id }}">Save</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif
@endsection

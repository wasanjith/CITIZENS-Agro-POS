@extends('layouts.app')

@section('title', 'Expense heads')

@section('content')
    <x-ui.page-header title="Expense heads" description="What money is spent on. Each head has its own account in the profit & loss.">
        <x-ui.button variant="secondary" :href="route('finance.expenses.index')">Expenses</x-ui.button>
    </x-ui.page-header>

    @error('name')<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror

    <form method="POST" action="{{ route('finance.expense-categories.store') }}" class="mb-6 flex max-w-xl items-end gap-3">
        @csrf
        <x-ui.input name="name" label="New expense head" maxlength="100" required class="flex-1" />
        <x-ui.button type="submit">Add</x-ui.button>
    </form>

    <x-ui.table>
        <x-slot:head>
            <th>Name</th>
            <th>Account</th>
            <th class="text-right">Expenses</th>
            <th>Active</th>
            <th></th>
        </x-slot:head>
        @foreach ($categories as $category)
            <tr x-data="{ editing: false }">
                <td>
                    <span x-show="! editing">{{ $category->name }}</span>
                    <form x-show="editing" x-cloak method="POST" action="{{ route('finance.expense-categories.update', $category) }}" id="category-{{ $category->id }}" class="flex items-center gap-2">
                        @csrf
                        @method('PUT')
                        <input type="text" name="name" value="{{ $category->name }}" maxlength="100" required class="rounded-md border-gray-300 text-sm" aria-label="Name">
                        <label class="flex items-center gap-1 text-sm"><input type="checkbox" name="is_active" value="1" @checked($category->is_active) class="rounded border-gray-300 text-brand-600"> Active</label>
                    </form>
                </td>
                <td class="font-mono text-xs text-gray-600">{{ $category->account->code }}</td>
                <td class="text-right tabular">{{ $category->expenses_count }}</td>
                <td>{{ $category->is_active ? 'Yes' : 'No' }}</td>
                <td class="text-right">
                    <x-ui.button size="sm" variant="ghost" x-show="! editing" x-on:click="editing = true">Edit</x-ui.button>
                    <x-ui.button size="sm" type="submit" x-show="editing" x-cloak form="category-{{ $category->id }}">Save</x-ui.button>
                </td>
            </tr>
        @endforeach
    </x-ui.table>
@endsection

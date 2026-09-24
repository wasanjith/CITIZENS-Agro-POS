@extends('layouts.app')

@section('title', 'Users')

@section('content')
    <x-ui.page-header title="Users" description="Staff accounts, roles and PINs.">
        <x-ui.button :href="route('admin.users.create')">Add user</x-ui.button>
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('admin.users.index')" placeholder="Search name or username" class="mb-4">
        <x-ui.select
            name="filter[role]"
            :options="collect($roles)->mapWithKeys(fn ($role) => [$role->value => $role->label()])->all()"
            :value="request('filter.role')"
            placeholder="All roles"
        />
        <x-ui.select
            name="filter[status]"
            :options="['active' => 'Active', 'inactive' => 'Inactive']"
            :value="request('filter.status')"
            placeholder="Any status"
        />
    </x-ui.filter-bar>

    @if ($users->isEmpty())
        <x-ui.empty-state title="No users found" />
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="name" default="name">Name</x-ui.th-sortable>
                <x-ui.th-sortable column="username" default="name">Username</x-ui.th-sortable>
                <th>Role</th>
                <th>PIN</th>
                <th>Status</th>
                <x-ui.th-sortable column="last_login_at" default="name">Last sign-in</x-ui.th-sortable>
                <th><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($users as $listedUser)
                <tr>
                    <td class="font-medium">{{ $listedUser->name }}</td>
                    <td class="text-gray-600">{{ $listedUser->username }}</td>
                    <td>
                        @php $role = $listedUser->primaryRole(); @endphp
                        <x-ui.badge :color="match ($role) { \App\Domain\Identity\Enums\Role::SuperAdmin => 'purple', \App\Domain\Identity\Enums\Role::Manager => 'blue', default => 'gray' }">
                            {{ $role?->label() ?? '—' }}
                        </x-ui.badge>
                    </td>
                    <td>{{ $listedUser->hasPin() ? 'Set' : '—' }}</td>
                    <td>
                        <x-ui.badge :color="$listedUser->is_active ? 'green' : 'red'">{{ $listedUser->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
                    </td>
                    <td class="text-gray-600">{{ $listedUser->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                    <td class="text-right">
                        <x-ui.button variant="link" :href="route('admin.users.edit', $listedUser)">Edit</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$users" />
    @endif
@endsection

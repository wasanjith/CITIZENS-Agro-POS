<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Actions\SaveUserAction;
use App\Domain\Identity\Enums\Role;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveUserRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    use HasListQuery;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $users = $this->applyListQuery(
            User::query()->with('roles'),
            $request,
            searchable: ['name', 'username', 'email'],
            sortable: ['name', 'username', 'last_login_at', 'created_at'],
            filters: [
                'role' => function (Builder $query, string $role): void {
                    $query->whereHas('roles', fn (Builder $roles) => $roles->where('name', $role));
                },
                'status' => function (Builder $query, string $status): void {
                    $query->where('is_active', $status === 'active');
                },
            ],
            defaultSort: 'name',
            defaultDirection: 'asc',
        )->paginate(25)->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'roles' => Role::cases(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('admin.users.form', [
            'user' => new User(['is_active' => true]),
            'roles' => Role::cases(),
        ]);
    }

    public function store(SaveUserRequest $request, SaveUserAction $saveUser): RedirectResponse
    {
        $user = $saveUser->handle($request->validated(), actingUser: $request->user());

        return redirect()->route('admin.users.index')->with('success', "User {$user->name} created.");
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        return view('admin.users.form', [
            'user' => $user->load('roles'),
            'roles' => Role::cases(),
        ]);
    }

    public function update(SaveUserRequest $request, User $user, SaveUserAction $saveUser): RedirectResponse
    {
        $saveUser->handle($request->validated(), $user, $request->user());

        return redirect()->route('admin.users.index')->with('success', "User {$user->name} updated.");
    }
}

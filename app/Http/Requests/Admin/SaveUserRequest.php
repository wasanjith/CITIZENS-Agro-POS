<?php

namespace App\Http\Requests\Admin;

use App\Domain\Identity\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User
            ? $this->user()->can('update', $user)
            : $this->user()->can('create', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->route('user');
        $isNew = ! $user instanceof User;
        $pin = config('pos.pin');

        return [
            'name' => ['required', 'string', 'max:100'],
            'username' => [
                'required', 'string', 'min:3', 'max:50', 'alpha_dash:ascii',
                Rule::unique('users', 'username')->ignore($user?->id),
            ],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user?->id)],
            'role' => ['required', Rule::enum(Role::class)],
            'is_active' => ['boolean'],
            'password' => [$isNew ? 'required' : 'nullable', 'string', Password::defaults(), 'confirmed'],
            'pin' => ['nullable', 'digits_between:'.$pin['min_length'].','.$pin['max_length'], 'confirmed'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => mb_strtolower((string) $this->input('username')),
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}

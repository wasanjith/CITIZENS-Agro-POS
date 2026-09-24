<?php

namespace App\Http\Requests\Admin;

use App\Domain\Identity\Enums\TerminalType;
use App\Domain\Identity\Models\Terminal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTerminalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $terminal = $this->route('terminal');

        return $terminal instanceof Terminal
            ? $this->user()->can('update', $terminal)
            : $this->user()->can('create', Terminal::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $terminal = $this->route('terminal');

        return [
            'name' => ['required', 'string', 'max:60'],
            'code' => ['required', 'string', 'max:10', 'alpha_dash:ascii', Rule::unique('terminals', 'code')->ignore($terminal?->id)],
            'type' => ['required', Rule::enum(TerminalType::class)],
            'counter_no' => [
                Rule::requiredIf($this->input('type') === TerminalType::Counter->value),
                'nullable', 'integer', 'between:1,9',
                Rule::unique('terminals', 'counter_no')->ignore($terminal?->id),
            ],
            'receipt_language' => ['nullable', 'in:si,en,si+en'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => mb_strtoupper((string) $this->input('code')),
            'is_active' => $this->boolean('is_active'),
            'counter_no' => $this->input('type') === TerminalType::Counter->value ? $this->input('counter_no') : null,
            'receipt_language' => $this->input('receipt_language') ?: null,
        ]);
    }
}

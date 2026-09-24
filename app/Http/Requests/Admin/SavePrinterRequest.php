<?php

namespace App\Http\Requests\Admin;

use App\Domain\Identity\Models\Printer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePrinterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $printer = $this->route('printer');

        return $printer instanceof Printer
            ? $this->user()->can('update', $printer)
            : $this->user()->can('create', Printer::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $printer = $this->route('printer');

        return [
            'name' => ['required', 'string', 'max:60'],
            'terminal_id' => ['nullable', 'integer', 'exists:terminals,id', Rule::unique('printers', 'terminal_id')->ignore($printer?->id)],
            'windows_name' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:80'],
            'paper_width_mm' => ['required', 'integer', 'in:58,80'],
            'dpi' => ['required', 'integer', 'between:150,600'],
            'has_cash_drawer' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'terminal_id.unique' => 'That terminal already has a printer. Unassign it first.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'terminal_id' => $this->input('terminal_id') ?: null,
            'has_cash_drawer' => $this->boolean('has_cash_drawer'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}

<?php

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Models\Category;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('category');

        return $category instanceof Category
            ? $this->user()->can('update', $category)
            : $this->user()->can('create', Category::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:100'],
            'name_si' => ['nullable', 'string', 'max:100'],
            'code_from' => ['nullable', 'required_with:code_to', 'integer', 'min:1', 'max:99999999'],
            'code_to' => ['nullable', 'required_with:code_from', 'integer', 'gte:code_from', 'max:99999999'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $category = $this->route('category');
                $ownId = $category instanceof Category ? $category->id : null;

                if ($ownId !== null && $this->filled('parent_id') && in_array((int) $this->input('parent_id'), $category->descendantIdsAndSelf(), true)) {
                    $validator->errors()->add('parent_id', 'A category cannot be placed under itself or one of its sub-categories.');
                }

                if ($this->filled('code_from') && $this->filled('code_to') && ! $validator->errors()->hasAny(['code_from', 'code_to'])) {
                    $overlap = Category::query()
                        ->when($ownId, fn ($query) => $query->whereKeyNot($ownId))
                        ->whereNotNull('code_from')
                        ->where('code_from', '<=', (int) $this->input('code_to'))
                        ->where('code_to', '>=', (int) $this->input('code_from'))
                        ->first();

                    if ($overlap !== null) {
                        $validator->errors()->add('code_from', "This range overlaps {$overlap->name} ({$overlap->code_from}–{$overlap->code_to}).");
                    }
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'parent_id' => $this->input('parent_id') ?: null,
            'code_from' => $this->input('code_from') ?: null,
            'code_to' => $this->input('code_to') ?: null,
            'sort_order' => $this->input('sort_order') ?: 0,
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}

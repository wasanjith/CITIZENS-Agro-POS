<?php

namespace App\Http\Requests\Pos;

use App\Domain\CashDrawer\Services\DrawerCalculator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A drawer count by denomination: denominations[5000] = 3, …, denominations[coins] = 37.50.
 */
class DrawerCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['denominations' => ['required', 'array']];

        foreach (DrawerCalculator::DENOMINATIONS as $note) {
            $rules["denominations.{$note}"] = ['nullable', 'integer', 'min:0', 'max:100000'];
        }

        $rules['denominations.coins'] = ['nullable', 'numeric', 'min:0', 'max:1000000'];

        return $rules + $this->extraRules();
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraRules(): array
    {
        return [];
    }

    /**
     * Only the denominations that were entered, as integers (coins as a money string).
     *
     * @return array<string, int|string>
     */
    public function denominations(): array
    {
        $input = (array) $this->validated('denominations', []);
        $count = [];

        foreach (DrawerCalculator::DENOMINATIONS as $note) {
            if ((int) ($input[$note] ?? 0) > 0) {
                $count[$note] = (int) $input[$note];
            }
        }

        if ((float) ($input['coins'] ?? 0) > 0) {
            $count['coins'] = number_format((float) $input['coins'], 2, '.', '');
        }

        return $count;
    }
}

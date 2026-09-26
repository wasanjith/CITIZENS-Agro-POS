<?php

namespace App\Domain\System\Import;

use App\Domain\Catalog\Import\ProductImportSheet;
use App\Domain\Customers\Actions\SaveCustomerAction;
use App\Domain\Finance\Services\FinancePosting;
use App\Domain\Purchasing\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Go-live import of the credit customers and suppliers from the old books, each with what
 * they owe / are owed (opening balance). Rows are checked first (preview); the import is all
 * or nothing, and each opening balance is posted to the journal like the forms do.
 */
class OpeningBalanceImporter
{
    public const TYPES = ['customers', 'suppliers'];

    public function __construct(
        private readonly SaveCustomerAction $saveCustomer,
        private readonly FinancePosting $finance,
    ) {}

    /**
     * @return list<string>
     */
    public static function headings(string $type): array
    {
        return $type === 'customers'
            ? ['name', 'name_si', 'phone', 'nic', 'address', 'area', 'credit_limit', 'credit_days', 'opening_balance', 'notes']
            : ['name', 'contact_person', 'phone', 'email', 'address', 'payment_terms_days', 'opening_balance'];
    }

    /**
     * @return list<list<string|int>>
     */
    public static function exampleRows(string $type): array
    {
        return $type === 'customers'
            ? [['Sunil Perera', 'සුනිල් පෙරේරා', '0771234567', '881234567V', 'No. 12, Main Street', 'Maho', 50000, 30, 12500, 'Paddy farmer']]
            : [['Lanka Fertilizer Co.', 'Mr. Silva', '0112345678', 'orders@example.lk', 'Colombo 10', 30, 250000]];
    }

    /**
     * @return array<int, array<string, mixed>> spreadsheet row number => values by heading
     */
    public function read(string $path): array
    {
        $rows = [];

        foreach (Excel::toArray(new ProductImportSheet, $path)[0] ?? [] as $index => $row) {
            $values = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $row);

            if (array_filter($values, fn ($value) => $value !== null && $value !== '') !== []) {
                $rows[$index + 2] = $values;
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{valid: array<int, array<string, mixed>>, errors: array<int, list<string>>, missing_columns: list<string>}
     */
    public function check(string $type, array $rows): array
    {
        $first = reset($rows) ?: [];
        $missing = array_values(array_diff(['name', 'opening_balance'], array_keys($first)));

        if ($rows !== [] && $missing !== []) {
            return ['valid' => [], 'errors' => [], 'missing_columns' => $missing];
        }

        $valid = [];
        $errors = [];
        $seen = [];

        foreach ($rows as $number => $row) {
            $data = $this->normalise($type, $row);
            $key = $type === 'customers' ? $data['phone'] : mb_strtolower((string) $data['name']);
            $validator = Validator::make($data, $this->rules($type));
            $rowErrors = $validator->errors()->all();

            if ($key !== null && $key !== '' && isset($seen[$key])) {
                $rowErrors[] = ($type === 'customers' ? 'Same phone number' : 'Same supplier name')." as row {$seen[$key]}.";
            }

            if ($key !== null && $key !== '') {
                $seen[$key] ??= $number;
            }

            if ($rowErrors === []) {
                $valid[$number] = $data;
            } else {
                $errors[$number] = $rowErrors;
            }
        }

        return ['valid' => $valid, 'errors' => $errors, 'missing_columns' => []];
    }

    /**
     * Create every row. All or nothing.
     *
     * @param  array<int, array<string, mixed>>  $valid
     */
    public function commit(string $type, array $valid, User $actor): int
    {
        return DB::transaction(function () use ($type, $valid, $actor): int {
            foreach ($valid as $data) {
                if ($type === 'customers') {
                    $this->saveCustomer->handle([...$data, 'is_active' => true], $actor);
                } else {
                    $supplier = Supplier::create([...$data, 'is_active' => true]);
                    $this->finance->supplierOpening($supplier, $actor->id);
                }
            }

            return count($valid);
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalise(string $type, array $row): array
    {
        $text = fn (string $key): ?string => isset($row[$key]) && trim((string) $row[$key]) !== '' ? trim((string) $row[$key]) : null;
        $number = fn (string $key): string => str_replace([',', ' '], '', (string) ($text($key) ?? '0'));

        if ($type === 'customers') {
            return [
                'name' => $text('name'),
                'name_si' => $text('name_si'),
                'phone' => $this->saveCustomer->normalisePhone($text('phone')),
                'nic' => $text('nic'),
                'address' => $text('address'),
                'area' => $text('area'),
                'credit_limit' => $number('credit_limit'),
                'credit_days' => $text('credit_days') ?? '30',
                'opening_balance' => $number('opening_balance'),
                'notes' => $text('notes'),
            ];
        }

        return [
            'name' => $text('name'),
            'contact_person' => $text('contact_person'),
            'phone' => $text('phone'),
            'email' => $text('email'),
            'address' => $text('address'),
            'payment_terms_days' => $text('payment_terms_days') ?? '30',
            'opening_balance' => $number('opening_balance'),
        ];
    }

    /**
     * The same rules as the customer and supplier forms.
     *
     * @return array<string, list<mixed>>
     */
    private function rules(string $type): array
    {
        if ($type === 'customers') {
            return [
                'name' => ['required', 'string', 'max:150'],
                'name_si' => ['nullable', 'string', 'max:150'],
                'phone' => ['nullable', 'string', 'min:9', 'max:15', Rule::unique('customers', 'phone')->whereNull('deleted_at')],
                'nic' => ['nullable', 'string', 'max:20', 'regex:/^(\d{9}[VvXx]|\d{12})$/'],
                'address' => ['nullable', 'string', 'max:255'],
                'area' => ['nullable', 'string', 'max:100'],
                'credit_limit' => ['numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
                'credit_days' => ['integer', 'min:0', 'max:365'],
                'opening_balance' => ['numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
                'notes' => ['nullable', 'string', 'max:500'],
            ];
        }

        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('suppliers', 'name')->whereNull('deleted_at')],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'payment_terms_days' => ['integer', 'min:0', 'max:365'],
            'opening_balance' => ['numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
        ];
    }
}

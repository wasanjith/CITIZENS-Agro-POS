<?php

namespace App\Domain\Catalog\Import;

use App\Domain\Catalog\Actions\SaveProductAction;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\OpeningStockEntry;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\ShortCodeGenerator;
use App\Http\Requests\Catalog\SaveProductRequest;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Product import: read → check every row → (after the user confirms) create all products
 * in one transaction. Only adds new products; an existing short code is an error.
 */
class ProductImporter
{
    /**
     * @var Collection<string, Unit>|null
     */
    private ?Collection $units = null;

    /**
     * @var array<string, Category>|null
     */
    private ?array $categories = null;

    public function __construct(
        private readonly ShortCodeGenerator $codes,
        private readonly SaveProductAction $saveProduct,
    ) {}

    /**
     * @return array<int, array<string, mixed>> spreadsheet row number => raw row
     */
    public function read(string $path): array
    {
        $sheets = Excel::toArray(new ProductImportSheet, $path);
        $rows = [];

        foreach ($sheets[0] ?? [] as $index => $row) {
            $values = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $row);

            if (array_filter($values, fn ($value) => $value !== null && $value !== '') === []) {
                continue;
            }

            // +2: one for the heading row, one because spreadsheets count from 1.
            $rows[$index + 2] = $values;
        }

        return $rows;
    }

    /**
     * Check all rows. Valid rows come back in the shape SaveProductAction expects.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{valid: array<int, array<string, mixed>>, errors: array<int, list<string>>, missing_columns: list<string>}
     */
    public function check(array $rows): array
    {
        $first = reset($rows) ?: [];
        $missing = array_values(array_diff(['name', 'category', 'base_unit'], array_keys($first)));

        if ($rows !== [] && $missing !== []) {
            return ['valid' => [], 'errors' => [], 'missing_columns' => $missing];
        }

        $valid = [];
        $errors = [];
        $seenCodes = [];

        foreach ($rows as $number => $row) {
            [$data, $rowErrors] = $this->checkRow($row, $seenCodes);

            if ($rowErrors === []) {
                $valid[$number] = $data;
                $seenCodes[] = $data['short_code'];
            } else {
                $errors[$number] = $rowErrors;

                if (($data['short_code'] ?? '') !== '') {
                    $seenCodes[] = $data['short_code'];
                }
            }
        }

        return ['valid' => $valid, 'errors' => $errors, 'missing_columns' => []];
    }

    /**
     * Create every product. All or nothing.
     *
     * @param  array<int, array<string, mixed>>  $valid
     */
    public function commit(array $valid, User $actor): int
    {
        $ids = Product::withoutSyncingToSearch(fn () => DB::transaction(function () use ($valid, $actor): array {
            $ids = [];

            foreach ($valid as $data) {
                $brandName = $data['brand_name'];
                $data['brand_id'] = $brandName !== null
                    ? Brand::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($brandName)])->value('id') ?? Brand::create(['name' => $brandName])->id
                    : null;

                $opening = $data['opening_stock'];
                unset($data['brand_name'], $data['opening_stock']);

                $product = $this->saveProduct->handle($data, $actor, index: false);
                $ids[] = $product->id;

                if ($opening !== null) {
                    OpeningStockEntry::create([...$opening, 'product_id' => $product->id, 'created_by' => $actor->id]);
                }
            }

            return $ids;
        }));

        // One batch to the search index instead of one request per product.
        $products = Product::query()->with(['category.parent.parent', 'brand', 'variants'])->whereIn('id', $ids)->get();
        $products->first()?->searchableUsing()->update($products);

        return count($ids);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $seenCodes
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function checkRow(array $row, array $seenCodes): array
    {
        $errors = [];
        $text = fn (string $key): ?string => ($value = trim((string) ($row[$key] ?? ''))) === '' ? null : $value;

        $name = $text('name');
        if ($name === null) {
            $errors[] = 'Name is empty.';
        } elseif (mb_strlen($name) > 150) {
            $errors[] = 'Name is longer than 150 characters.';
        }

        $category = null;
        if (($categoryName = $text('category')) === null) {
            $errors[] = 'Category is empty.';
        } elseif (($category = $this->findCategory($categoryName)) === null) {
            $errors[] = "Unknown category \"{$categoryName}\".";
        }

        $baseUnit = null;
        if (($baseUnitName = $text('base_unit')) === null) {
            $errors[] = 'Base unit is empty.';
        } elseif (($baseUnit = $this->findUnit($baseUnitName)) === null) {
            $errors[] = "Unknown unit \"{$baseUnitName}\".";
        }

        // Units: base unit (factor 1) + "bag=50; packet=5".
        $units = $baseUnit ? [$baseUnit->id => '1'] : [];
        foreach (array_filter(array_map('trim', preg_split('/[;,]+/', (string) $text('sale_units')) ?: [])) as $pair) {
            if (preg_match('/^(.+?)\s*[=:]\s*(\d+(?:\.\d{1,3})?)$/u', $pair, $matches) !== 1 || (float) $matches[2] <= 0) {
                $errors[] = "Sale unit \"{$pair}\" should look like bag=50.";

                continue;
            }
            if (($unit = $this->findUnit($matches[1])) === null) {
                $errors[] = "Unknown unit \"{$matches[1]}\".";

                continue;
            }
            $units[$unit->id] = $matches[2];
        }

        $defaultSaleUnit = $baseUnit;
        if (($defaultName = $text('default_sale_unit')) !== null) {
            $defaultSaleUnit = $this->findUnit($defaultName);
            if ($defaultSaleUnit === null || ! isset($units[$defaultSaleUnit->id])) {
                $errors[] = "Default sale unit \"{$defaultName}\" must be the base unit or one of the sale units.";
            }
        }

        // Short code: given, or the next free one in the category range.
        $code = mb_strtoupper((string) $text('short_code'));
        if ($code === '' && $category !== null) {
            $code = (string) $this->codes->next($category, $seenCodes);
            if ($code === '') {
                $errors[] = "No free short code left in the range of {$category->name}.";
            }
        } elseif ($code !== '') {
            if (preg_match(SaveProductRequest::CODE_PATTERN, $code) !== 1 || mb_strlen($code) > 20) {
                $errors[] = "Short code \"{$code}\" may only contain letters, numbers and dashes (max 20).";
            } elseif (in_array($code, $seenCodes, true)) {
                $errors[] = "Short code {$code} appears more than once in the file.";
            } elseif ($this->codes->isTaken($code)) {
                $errors[] = "Short code {$code} already exists.";
            }
        }

        $numbers = [];
        foreach (['retail_price' => 2, 'wholesale_price' => 2, 'reorder_level' => 3, 'reorder_qty' => 3, 'opening_stock' => 3, 'cost' => 4] as $key => $scale) {
            $value = $row[$key] ?? null;
            if ($value === null || $value === '') {
                $numbers[$key] = null;

                continue;
            }
            $clean = str_replace([',', ' ', 'Rs.', 'Rs'], '', (string) $value);
            if (! is_numeric($clean) || (float) $clean < 0) {
                $errors[] = str_replace('_', ' ', ucfirst($key))." \"{$value}\" is not a valid number.";
                $numbers[$key] = null;

                continue;
            }
            $numbers[$key] = (string) BigDecimal::of($clean)->toScale($scale, RoundingMode::HalfUp);
        }

        $expiry = null;
        if (($rawExpiry = $row['expiry_date'] ?? null) !== null && $rawExpiry !== '') {
            try {
                $expiry = is_numeric($rawExpiry)
                    ? Carbon::instance(ExcelDate::excelToDateTimeObject((float) $rawExpiry))->toDateString()
                    : Carbon::parse((string) $rawExpiry)->toDateString();
            } catch (Throwable) {
                $errors[] = "Expiry date \"{$rawExpiry}\" is not a valid date.";
            }
        }

        if (($numbers['opening_stock'] ?? null) !== null && $baseUnit !== null && ! $baseUnit->allows_decimal
            && ! BigDecimal::of($numbers['opening_stock'])->isEqualTo(BigDecimal::of($numbers['opening_stock'])->toScale(0, RoundingMode::Down))) {
            $errors[] = "Opening stock must be a whole number of {$baseUnit->name}.";
        }

        $data = [
            'short_code' => $code,
            'name' => $name,
            'name_si' => $text('name_si'),
            'name_ta' => $text('name_ta'),
            'aliases' => $text('aliases'),
            'category_id' => $category?->id,
            'category_path' => $category?->path(),
            'brand_name' => $text('brand'),
            'base_unit_id' => $baseUnit?->id,
            'base_unit_name' => $baseUnit?->name,
            'is_active' => true,
            'track_batches' => $text('batch_no') !== null,
            'track_expiry' => $expiry !== null,
            'reorder_level' => $numbers['reorder_level'] ?? '0',
            'reorder_qty' => $numbers['reorder_qty'] ?? '0',
            'reference_cost' => $numbers['cost'] ?? null,
            'units' => [],
            'prices' => [],
            'variants' => [],
            'opening_stock' => null,
        ];

        if ($errors !== [] || $baseUnit === null || $defaultSaleUnit === null) {
            return [$data, $errors];
        }

        foreach ($units as $unitId => $factor) {
            $data['units'][] = [
                'unit_id' => $unitId,
                'factor' => $factor,
                'is_default_sale' => $unitId === $defaultSaleUnit->id,
                'is_default_purchase' => $unitId === $defaultSaleUnit->id,
            ];
        }

        $data['prices'] = $this->prices($units, $defaultSaleUnit->id, $numbers);

        if (($numbers['opening_stock'] ?? null) !== null && BigDecimal::of($numbers['opening_stock'])->isPositive()) {
            $data['opening_stock'] = [
                'qty' => $numbers['opening_stock'],
                'unit_cost' => $numbers['cost'] ?? null,
                'lot_no' => $text('batch_no'),
                'expiry_date' => $expiry,
            ];
        }

        return [$data, []];
    }

    /**
     * The file gives the price of the default sale unit; every other unit gets the
     * proportional price (bag 9000, factor 50 → kg 180.00). Editable later.
     *
     * @param  array<int, string>  $units  unit_id => factor
     * @param  array<string, string|null>  $numbers
     * @return list<array{unit_id: int, price_list_id: int, price: string}>
     */
    private function prices(array $units, int $defaultUnitId, array $numbers): array
    {
        $prices = [];
        $lists = PriceList::query()->pluck('id', 'name');

        foreach (['Retail' => 'retail_price', 'Wholesale' => 'wholesale_price'] as $listName => $column) {
            if (($numbers[$column] ?? null) === null || ! $lists->has($listName)) {
                continue;
            }

            $perBaseUnit = BigDecimal::of($numbers[$column])->dividedBy($units[$defaultUnitId], 6, RoundingMode::HalfUp);

            foreach ($units as $unitId => $factor) {
                $prices[] = [
                    'unit_id' => $unitId,
                    'price_list_id' => $lists[$listName],
                    'price' => $unitId === $defaultUnitId
                        ? $numbers[$column]
                        : (string) $perBaseUnit->multipliedBy($factor)->toScale(2, RoundingMode::HalfUp),
                ];
            }
        }

        return $prices;
    }

    private function findUnit(string $name): ?Unit
    {
        $this->units ??= Unit::all()->flatMap(fn (Unit $unit) => [
            mb_strtolower($unit->name) => $unit,
            mb_strtolower($unit->symbol) => $unit,
        ]);

        return $this->units->get(mb_strtolower(trim($name)));
    }

    /**
     * "Tyres" or "Bicycle Parts > Tyres" (also › or /).
     */
    private function findCategory(string $name): ?Category
    {
        if ($this->categories === null) {
            $this->categories = [];
            $all = Category::query()->with('parent.parent')->get();

            foreach ($all as $category) {
                $this->categories[mb_strtolower($category->path())] = $category;
            }
            // A plain name works too when it is unique.
            foreach ($all->groupBy(fn (Category $category) => mb_strtolower($category->name)) as $key => $matches) {
                if ($matches->count() === 1) {
                    $this->categories[$key] ??= $matches->first();
                }
            }
        }

        $key = mb_strtolower(implode(' › ', array_map('trim', preg_split('/\s*[>›\/]\s*/u', trim($name)) ?: [])));

        return $this->categories[$key] ?? null;
    }
}

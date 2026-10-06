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
use App\Domain\Inventory\Actions\PostOpeningStockAction;
use App\Domain\Inventory\Support\Qty;
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
 *
 * Works with the template and with the owner's own price sheet:
 *   - every pack size is its own product: "Okra" + pack "10g packet" → "Okra 10g packet";
 *     a row with only a pack size continues the product above (name, category, brand …)
 *   - a row with "Price for Kg's" / "Price for grams" is a loose product with a price per kg
 *     under 1 kg and one from 1 kg
 *   - prices are only what the file says; nothing is worked out for other units
 *   - "Opens Into" links a sealed bag to its loose product (in the file or already saved)
 *
 * Notes (warnings) do not stop the import: a missing price, a corrected base unit …
 */
class ProductImporter
{
    /**
     * Units that measure an amount; a sealed pack is counted in packets, bags, bottles … instead.
     */
    private const MEASURE_UNITS = ['g', 'kg', 'ml', 'litre', 'metre'];

    /**
     * Other ways the shop writes unit names.
     */
    private const UNIT_ALIASES = [
        'gram' => 'g', 'grams' => 'g', 'gm' => 'g', 'gms' => 'g',
        'kgs' => 'kg', 'kilo' => 'kg', 'kilos' => 'kg', 'kilogram' => 'kg', 'kilograms' => 'kg',
        'packets' => 'packet', 'pack' => 'packet', 'packs' => 'packet',
        'bags' => 'bag', 'bottles' => 'bottle', 'pieces' => 'piece', 'pcs' => 'piece',
        'liter' => 'litre', 'liters' => 'litre', 'litres' => 'litre',
    ];

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
        private readonly PostOpeningStockAction $postOpeningStock,
    ) {}

    /**
     * @return array<int, array<string, mixed>> spreadsheet row number => row keyed by column key
     */
    public function read(string $path): array
    {
        $sheets = Excel::toArray(new ProductImportSheet, $path);
        $rows = [];

        foreach ($sheets[0] ?? [] as $index => $row) {
            $values = [];

            foreach ($row as $heading => $value) {
                $key = ProductImportColumns::key((string) $heading);
                $value = is_string($value) ? trim($value) : $value;

                // Two headings for the same column: the first one with a value wins.
                if ($key !== null && (! array_key_exists($key, $values) || $values[$key] === null || $values[$key] === '')) {
                    $values[$key] = $value;
                }
            }

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
     * @return array{valid: array<int, array<string, mixed>>, errors: array<int, list<string>>, warnings: array<int, list<string>>, missing_columns: list<string>}
     */
    public function check(array $rows): array
    {
        $first = reset($rows) ?: [];
        $missing = array_values(array_diff(['name', 'category', 'base_unit'], array_keys($first)));

        if ($rows !== [] && $missing !== []) {
            $headings = array_map(fn (string $key) => ProductImportColumns::all()[$key]['heading'], $missing);

            return ['valid' => [], 'errors' => [], 'warnings' => [], 'missing_columns' => $headings];
        }

        $valid = [];
        $errors = [];
        $warnings = [];
        $seenCodes = [];
        $names = [];
        $group = null;

        foreach ($rows as $number => $row) {
            [$row, $group] = $this->continueGroup($row, $group);
            [$data, $rowErrors, $rowWarnings] = $this->checkRow($row, $seenCodes);

            $nameKey = mb_strtolower((string) $data['name']);
            if ($nameKey !== '' && isset($names[$nameKey])) {
                $rowWarnings[] = "Same name as row {$names[$nameKey]}. Staff will not be able to tell them apart.";
            }
            $names[$nameKey] ??= $number;

            if ($rowErrors === []) {
                $valid[$number] = $data;
            } else {
                $errors[$number] = $rowErrors;
            }

            if (($data['short_code'] ?? '') !== '') {
                $seenCodes[] = $data['short_code'];
            }

            if ($rowWarnings !== []) {
                $warnings[$number] = $rowWarnings;
            }
        }

        // Links to loose products, now that every row of the file is known.
        foreach ($valid as $number => $data) {
            if ($data['opens_into_text'] === null) {
                continue;
            }

            $linkErrors = $this->resolveOpensInto($valid, $number);

            if ($linkErrors !== []) {
                unset($valid[$number]);
                $errors[$number] = $linkErrors;
            }
        }

        ksort($errors);

        return ['valid' => $valid, 'errors' => $errors, 'warnings' => $warnings, 'missing_columns' => []];
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
            $openingIds = [];
            $links = [];

            foreach ($valid as $number => $data) {
                $brandName = $data['brand_name'];
                $data['brand_id'] = $brandName !== null
                    ? Brand::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($brandName)])->value('id') ?? Brand::create(['name' => $brandName])->id
                    : null;

                $opening = $data['opening_stock'];
                $openRow = $data['opens_into_row'] ?? null;
                unset($data['brand_name'], $data['opening_stock'], $data['opens_into_row']);

                $product = $this->saveProduct->handle($data, $actor, index: false);
                $ids[$number] = $product->id;

                if ($openRow !== null) {
                    $links[$product->id] = [$openRow, $data['opens_into_qty']];
                }

                if ($opening !== null) {
                    $openingIds[] = OpeningStockEntry::create([...$opening, 'product_id' => $product->id, 'created_by' => $actor->id])->id;
                }
            }

            // Sealed bags that open into a loose product from the same file.
            foreach ($links as $productId => [$row, $qty]) {
                Product::query()->whereKey($productId)->update(['opens_into_product_id' => $ids[$row], 'opens_into_qty' => $qty]);
            }

            // Opening stock goes straight into inventory as OPENING movements.
            if ($openingIds !== []) {
                $this->postOpeningStock->handle($actor, $openingIds);
            }

            return array_values($ids);
        }));

        // One batch to the search index instead of one request per product.
        $products = Product::query()->with(['category.parent.parent', 'brand', 'variants'])->whereIn('id', $ids)->get();
        $products->first()?->searchableUsing()->update($products);

        return count($ids);
    }

    /**
     * A row without a name but with a pack size is the next pack of the product above:
     * it takes that product's name, category, brand and units where it has none.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $group  last row that had a name
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    private function continueGroup(array $row, ?array $group): array
    {
        if (trim((string) ($row['name'] ?? '')) !== '') {
            return [$row, $row];
        }

        if ($group === null || trim((string) ($row['pack_size'] ?? '')) === '') {
            return [$row, $group];
        }

        foreach (['name', 'name_si', 'name_ta', 'aliases', 'category', 'brand', 'base_unit', 'default_sale_unit'] as $key) {
            if (trim((string) ($row[$key] ?? '')) === '') {
                $row[$key] = $group[$key] ?? null;
            }
        }

        return [$row, $group];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $seenCodes
     * @return array{0: array<string, mixed>, 1: list<string>, 2: list<string>}
     */
    private function checkRow(array $row, array $seenCodes): array
    {
        $errors = [];
        $warnings = [];
        $text = fn (string $key): ?string => ($value = trim((string) ($row[$key] ?? ''))) === '' ? null : $value;

        $pack = $text('pack_size');
        $name = $this->withPack($text('name'), $pack);
        if ($name === null) {
            $errors[] = 'Product Name is empty.';
        } elseif (mb_strlen($name) > 150) {
            $errors[] = 'Product Name is longer than 150 characters.';
        }

        $category = null;
        if (($categoryName = $text('category')) === null) {
            $errors[] = 'Category is empty.';
        } elseif (($category = $this->findCategory($categoryName)) === null) {
            $errors[] = "Unknown category \"{$categoryName}\".";
        }

        $numbers = [];
        foreach ([
            'retail_price' => 2, 'wholesale_price' => 2, 'loose_price_kg' => 2, 'loose_price_small' => 2,
            'reorder_level' => 3, 'reorder_qty' => 3, 'opening_stock' => 3, 'cost' => 4, 'opens_into_qty' => 3,
        ] as $key => $scale) {
            $value = $row[$key] ?? null;
            if ($value === null || $value === '') {
                $numbers[$key] = null;

                continue;
            }
            $clean = str_replace([',', ' ', 'Rs.', 'Rs'], '', (string) $value);
            if (! is_numeric($clean) || (float) $clean < 0) {
                $errors[] = ProductImportColumns::all()[$key]['heading']." \"{$value}\" is not a valid number.";
                $numbers[$key] = null;

                continue;
            }
            $numbers[$key] = (string) BigDecimal::of($clean)->toScale($scale, RoundingMode::HalfUp);
        }

        $loose = $numbers['loose_price_kg'] !== null || $numbers['loose_price_small'] !== null;

        if ($loose && $pack !== null) {
            $errors[] = "A row is either a sealed pack (Pack Size) or loose (Price for Kg's / grams), not both.";
        }

        $baseUnit = null;
        if (($baseUnitName = $text('base_unit')) === null) {
            $errors[] = 'Base Unit is empty.';
        } elseif (($baseUnit = $this->findUnit($baseUnitName)) === null) {
            $errors[] = "Unknown unit \"{$baseUnitName}\".";
        } else {
            $baseUnit = $this->correctBaseUnit($baseUnit, $pack, $loose, $errors, $warnings);
        }

        // Units: base unit (factor 1) + "bag=50; box=10".
        $units = $baseUnit ? [$baseUnit->id => '1'] : [];
        foreach (array_filter(array_map('trim', preg_split('/[;,]+/', (string) $text('sale_units')) ?: [])) as $pair) {
            if (preg_match('/^(.+?)\s*[=:]\s*(\d+(?:\.\d{1,3})?)$/u', $pair, $matches) !== 1 || (float) $matches[2] <= 0) {
                $errors[] = "Other unit \"{$pair}\" should look like bag=50.";

                continue;
            }
            if (($unit = $this->findUnit($matches[1])) === null) {
                $errors[] = "Unknown unit \"{$matches[1]}\".";

                continue;
            }
            if ($unit->id !== $baseUnit?->id) {
                $units[$unit->id] = $matches[2];
            }
        }

        // The base unit as written in the file also counts, in case it was corrected above.
        $defaultSaleUnit = $baseUnit;
        if (($defaultName = $text('default_sale_unit')) !== null) {
            $found = $this->findUnit($defaultName);

            if ($found !== null && isset($units[$found->id])) {
                $defaultSaleUnit = $found;
            } elseif ($found === null || $found->id !== $this->findUnit((string) $baseUnitName)?->id) {
                $errors[] = "Default Sale Unit \"{$defaultName}\" must be the base unit or one of the other units.";
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

        $opensInto = $text('opens_into');
        if ($opensInto !== null && $loose) {
            $errors[] = 'A loose product cannot be opened into another product. Only sealed packs can.';
        }

        $data = [
            'short_code' => $code,
            'name' => $name,
            'name_si' => $this->withPack($text('name_si'), $pack),
            'name_ta' => $this->withPack($text('name_ta'), $pack),
            'aliases' => $text('aliases'),
            'category_id' => $category?->id,
            'category_path' => $category?->path(),
            'brand_name' => $text('brand'),
            'base_unit_id' => $baseUnit?->id,
            'base_unit_name' => $baseUnit?->name,
            'sold_loose' => $loose,
            'pack_size' => $pack,
            'opens_into_text' => $opensInto,
            'opens_into_product_id' => null,
            'opens_into_qty' => $numbers['opens_into_qty'],
            'is_active' => true,
            'track_batches' => $text('batch_no') !== null,
            'track_expiry' => $expiry !== null,
            'reorder_level' => $numbers['reorder_level'] ?? '0',
            'reorder_qty' => $numbers['reorder_qty'] ?? '0',
            'reference_cost' => $numbers['cost'] ?? null,
            'units' => [],
            'prices' => [],
            'price_text' => '',
            'variants' => [],
            'opening_stock' => null,
        ];

        if ($errors !== [] || $baseUnit === null || $defaultSaleUnit === null) {
            return [$data, $errors, $warnings];
        }

        foreach ($units as $unitId => $factor) {
            $data['units'][] = [
                'unit_id' => $unitId,
                'factor' => $factor,
                'is_default_sale' => $unitId === $defaultSaleUnit->id,
                'is_default_purchase' => $unitId === $defaultSaleUnit->id,
            ];
        }

        [$data['prices'], $data['price_text']] = $this->prices($loose ? $baseUnit : $defaultSaleUnit, $numbers, $loose, $errors, $warnings);

        if (($numbers['opening_stock'] ?? null) !== null && BigDecimal::of($numbers['opening_stock'])->isPositive()) {
            $data['opening_stock'] = [
                'qty' => $numbers['opening_stock'],
                'unit_cost' => $numbers['cost'] ?? null,
                'lot_no' => $text('batch_no'),
                'expiry_date' => $expiry,
            ];
        }

        return [$data, $errors, $warnings];
    }

    /**
     * "Okra" + "10g packet" → "Okra 10g packet" (unless the name already says it).
     */
    private function withPack(?string $name, ?string $pack): ?string
    {
        if ($name === null || $pack === null || str_contains(mb_strtolower($name), mb_strtolower($pack))) {
            return $name;
        }

        return "{$name} {$pack}";
    }

    /**
     * A sealed pack is counted in packets (bags, bottles …), not grams; loose goods are
     * priced per kg, so they are counted in kg.
     *
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function correctBaseUnit(Unit $unit, ?string $pack, bool $loose, array &$errors, array &$warnings): Unit
    {
        if ($pack !== null && ! $loose && in_array($unit->name, self::MEASURE_UNITS, true)) {
            $packUnit = collect(preg_split('/[^\p{L}]+/u', mb_strtolower($pack)) ?: [])
                ->map(fn (string $word) => $word !== '' ? $this->findUnit($word) : null)
                ->first(fn (?Unit $found) => $found !== null && ! in_array($found->name, self::MEASURE_UNITS, true))
                ?? $this->findUnit('packet');

            if ($packUnit !== null) {
                $warnings[] = "Base unit {$unit->name} changed to {$packUnit->name}: each {$pack} is counted as one {$packUnit->name}.";

                return $packUnit;
            }
        }

        if ($loose && ! $unit->allows_decimal) {
            if ($unit->name === 'g' && ($kg = $this->findUnit('kg')) !== null) {
                $warnings[] = 'Base unit g changed to kg: loose prices are per kg.';

                return $kg;
            }

            $errors[] = "Loose goods are weighed, so the base unit must allow decimals (kg), not {$unit->name}.";
        }

        return $unit;
    }

    /**
     * Only the prices the file gives, on the default sale unit. Loose goods: the price per kg
     * under 1 kg (Price for grams, or Selling Price) and from 1 kg (Price for Kg's).
     *
     * @param  array<string, string|null>  $numbers
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @return array{0: list<array{unit_id: int, price_list_id: int, min_qty: string, price: string}>, 1: string}
     */
    private function prices(Unit $unit, array $numbers, bool $loose, array &$errors, array &$warnings): array
    {
        $lists = PriceList::query()->pluck('id', 'name');
        $retailId = $lists['Retail'] ?? PriceList::default()?->id;
        $prices = [];
        $text = [];
        $add = function (?int $listId, string $minQty, string $price) use (&$prices, $unit): void {
            if ($listId !== null) {
                $prices[] = ['unit_id' => $unit->id, 'price_list_id' => $listId, 'min_qty' => $minQty, 'price' => $price];
            }
        };

        if ($loose) {
            $small = $numbers['loose_price_small'];
            $kg = $numbers['loose_price_kg'];

            if ($small !== null && $numbers['retail_price'] !== null && ! BigDecimal::of($small)->isEqualTo($numbers['retail_price'])) {
                $errors[] = 'Selling Price and Price for grams are different. For loose goods leave Selling Price empty.';
            }
            $small ??= $numbers['retail_price'];

            if ($small !== null && $kg !== null && BigDecimal::of($small)->multipliedBy(2)->isLessThan($kg)) {
                $errors[] = "Price for grams ({$small}) is much lower than the price for kg's ({$kg}). Write it as a price per kg: Rs. 30 for 100 g is 300.";
            }

            if ($small === null || $kg === null || BigDecimal::of($small)->isEqualTo($kg)) {
                $rate = (string) ($small ?? $kg);
                $add($retailId, '0', $rate);
                $text[] = "Rs. {$rate} / {$unit->symbol}";
            } else {
                $add($retailId, '0', $small);
                $add($retailId, '1', $kg);
                $text[] = "under 1 {$unit->symbol}: {$small} / {$unit->symbol} · from 1 {$unit->symbol}: {$kg} / {$unit->symbol}";

                if (BigDecimal::of($small)->isLessThan($kg)) {
                    $warnings[] = 'Small amounts (under 1 kg) are cheaper than 1 kg and above. Check the two loose prices.';
                }
            }
        } elseif ($numbers['retail_price'] !== null) {
            $add($retailId, '0', $numbers['retail_price']);
            $text[] = "Rs. {$numbers['retail_price']} / {$unit->symbol}";
        } else {
            $warnings[] = 'No Selling Price: it is imported but cannot be billed until a price is set.';
        }

        if ($numbers['wholesale_price'] !== null) {
            $add($lists['Wholesale'] ?? null, '0', $numbers['wholesale_price']);
            $text[] = "wholesale {$numbers['wholesale_price']}";
        }

        return [$prices, implode(' · ', $text)];
    }

    /**
     * Find the loose product an "Opens Into" row points at: a loose row of this file or
     * a loose product already saved. Fills opens_into_row / opens_into_product_id and the
     * quantity per pack (given, or read from the pack size: "50kg bag" → 50).
     *
     * @param  array<int, array<string, mixed>>  $valid
     * @return list<string> errors
     */
    private function resolveOpensInto(array &$valid, int $number): array
    {
        $data = $valid[$number];
        $wanted = mb_strtolower((string) $data['opens_into_text']);
        $targetUnit = null;

        foreach ($valid as $otherNumber => $other) {
            if ($otherNumber !== $number && $other['sold_loose'] && (mb_strtolower((string) $other['name']) === $wanted || mb_strtolower($other['short_code']) === $wanted)) {
                $valid[$number]['opens_into_row'] = $otherNumber;
                $targetUnit = $other['base_unit_name'];
                break;
            }
        }

        if ($targetUnit === null) {
            $product = Product::query()
                ->with('baseUnit')
                ->where('sold_loose', true)
                ->where(fn ($query) => $query->where('short_code', mb_strtoupper($wanted))->orWhereRaw('LOWER(name) = ?', [$wanted]))
                ->first();

            if ($product === null) {
                return ["Opens Into \"{$data['opens_into_text']}\": no loose product with that name or code in this file or in the shop."];
            }

            $valid[$number]['opens_into_product_id'] = $product->id;
            $targetUnit = $product->baseUnit?->name;
        }

        if ($data['opens_into_qty'] === null) {
            $perPack = $targetUnit === 'kg' ? $this->kgInPack((string) $data['pack_size']) : null;

            if ($perPack === null) {
                return ['Enter Loose Qty Per Pack: how much loose product one pack gives (e.g. 50 for a 50 kg bag).'];
            }

            $valid[$number]['opens_into_qty'] = $perPack;
        }

        return [];
    }

    /**
     * "50kg bag" → "50.000", "500g packet" → "0.500"; null when the pack size has no weight.
     */
    private function kgInPack(string $pack): ?string
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*(kg|g)\b/iu', $pack, $matches) !== 1) {
            return null;
        }

        $qty = mb_strtolower($matches[2]) === 'kg' ? Qty::of($matches[1]) : Qty::of($matches[1])->dividedBy(1000, Qty::SCALE, RoundingMode::HalfUp);

        return $qty->isPositive() ? (string) $qty : null;
    }

    private function findUnit(string $name): ?Unit
    {
        $this->units ??= Unit::all()->flatMap(fn (Unit $unit) => [
            mb_strtolower($unit->name) => $unit,
            mb_strtolower($unit->symbol) => $unit,
        ]);

        $key = mb_strtolower(trim($name));

        return $this->units->get($key) ?? $this->units->get(self::UNIT_ALIASES[$key] ?? '');
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

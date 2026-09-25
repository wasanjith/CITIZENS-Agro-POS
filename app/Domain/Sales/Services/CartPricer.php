<?php

namespace App\Domain\Sales\Services;

use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Catalog\Services\PriceBook;
use App\Domain\Inventory\Support\Qty;
use App\Domain\Sales\Enums\ApprovalStatus;
use App\Domain\Sales\Enums\ApprovalType;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Models\ApprovalRequest;
use App\Domain\Sales\Support\Money;
use App\Domain\System\Services\Settings;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Prices a counter cart on the server. Client prices are never trusted: every line is
 * repriced from the price book, discounts are checked against the user's limit and
 * approved requests, and totals are recomputed.
 *
 * Input (from the POS screen):
 *   cart_uuid, price_list_id, payment_method, tendered, bill_discount, bill_approval_request_id,
 *   lines: [{key, product_id, variant_id, unit_id, qty, discount, approval_request_id}]
 *
 * strict = true (printing an invoice): anything wrong throws a ValidationException.
 * strict = false (live cart sync): lines that cannot be priced are dropped.
 */
class CartPricer
{
    public function __construct(
        private readonly PriceBook $priceBook,
        private readonly DiscountLimits $limits,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $cart
     * @return array<string, mixed>
     */
    public function price(array $cart, User $user, bool $strict = true): array
    {
        $cartUuid = (string) ($cart['cart_uuid'] ?? '');
        $priceListId = (int) ($cart['price_list_id'] ?? 0) ?: (int) PriceList::default()?->id;
        $rawLines = array_values(array_filter((array) ($cart['lines'] ?? []), 'is_array'));

        $productIds = array_values(array_unique(array_map(fn (array $line) => (int) ($line['product_id'] ?? 0), $rawLines)));
        /** @var Collection<int, Product> $products */
        $products = Product::query()
            ->with(['units.unit', 'variants', 'tax'])
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');
        $prices = $this->priceBook->forProducts($productIds, $priceListId);
        $approvals = $this->approvals($cartUuid, $rawLines, $cart);
        $maxPercent = $this->limits->maxPercentFor($user);
        $taxRegistered = (bool) $this->settings->get('tax.registered', false);

        $lines = [];
        $subtotal = Money::zero();
        $lineDiscounts = Money::zero();
        $lineTax = Money::zero();

        foreach ($rawLines as $index => $raw) {
            $field = "lines.{$index}";
            $line = $this->priceLine($raw, $field, $products, $prices, $strict);

            if ($line === null) {
                continue;
            }

            /** @var BigDecimal $gross */
            $gross = $line['_gross'];
            $discount = Money::of($raw['discount'] ?? '0');

            if ($discount->isNegative() || $discount->isGreaterThan($gross)) {
                $this->fail($strict, "{$field}.discount", 'The discount must be between 0 and the line amount.');
                $discount = $discount->isNegative() ? Money::zero() : $gross;
            }

            $percent = Money::percent($discount, $gross);
            $approval = $approvals[(int) ($raw['approval_request_id'] ?? 0)] ?? null;
            $approved = $approval !== null
                && ($approval->payload['scope'] ?? null) === 'line'
                && ($approval->payload['line_key'] ?? null) === $line['key']
                && Money::of($approval->payload['amount'] ?? '0')->isGreaterThanOrEqualTo($discount);
            $needsApproval = $discount->isPositive() && $maxPercent !== null && $percent->isGreaterThan($maxPercent) && ! $approved;

            $total = $gross->minus($discount);
            $tax = $taxRegistered ? $this->includedTax($total, $line['_tax_rate']) : Money::zero();

            $subtotal = $subtotal->plus($gross);
            $lineDiscounts = $lineDiscounts->plus($discount);
            $lineTax = $lineTax->plus($tax);

            unset($line['_gross'], $line['_tax_rate']);
            $lines[] = [
                ...$line,
                'gross' => (string) $gross,
                'discount' => (string) $discount,
                'discount_percent' => (string) $percent,
                'tax' => (string) $tax,
                'line_total' => (string) $total,
                'needs_approval' => $needsApproval,
                'approval_request_id' => $approved ? $approval->id : null,
            ];
        }

        $net = $subtotal->minus($lineDiscounts);
        $billDiscount = Money::of($cart['bill_discount'] ?? '0');

        if ($billDiscount->isNegative() || $billDiscount->isGreaterThan($net)) {
            $this->fail($strict, 'bill_discount', 'The bill discount must be between 0 and the bill amount.');
            $billDiscount = $billDiscount->isNegative() ? Money::zero() : $net;
        }

        $billPercent = Money::percent($billDiscount, $net);
        $billApproval = $approvals[(int) ($cart['bill_approval_request_id'] ?? 0)] ?? null;
        $billApproved = $billApproval !== null
            && ($billApproval->payload['scope'] ?? null) === 'bill'
            && Money::of($billApproval->payload['amount'] ?? '0')->isGreaterThanOrEqualTo($billDiscount);
        $billNeedsApproval = $billDiscount->isPositive() && $maxPercent !== null && $billPercent->isGreaterThan($maxPercent) && ! $billApproved;

        $total = $net->minus($billDiscount);
        // Prices include tax; a bill discount lowers the tax in the same proportion.
        $taxTotal = $net->isZero() ? Money::zero() : $lineTax->multipliedBy($total)->dividedBy($net, 2, RoundingMode::HalfUp);

        $method = PaymentMethod::tryFrom((string) ($cart['payment_method'] ?? '')) ?? PaymentMethod::Cash;
        $tendered = isset($cart['tendered']) && $cart['tendered'] !== '' ? Money::of($cart['tendered']) : null;
        $change = $method === PaymentMethod::Cash && $tendered !== null && $tendered->isGreaterThanOrEqualTo($total)
            ? $tendered->minus($total)
            : Money::zero();

        return [
            'cart_uuid' => $cartUuid,
            'price_list_id' => $priceListId,
            'lines' => $lines,
            'subtotal' => (string) $subtotal,
            'line_discount_total' => (string) $lineDiscounts,
            'bill_discount' => (string) $billDiscount,
            'bill_discount_percent' => (string) $billPercent,
            'bill_needs_approval' => $billNeedsApproval,
            'bill_approval_request_id' => $billApproved ? $billApproval->id : null,
            'tax_total' => (string) $taxTotal,
            'total' => (string) $total,
            'payment_method' => $method->value,
            'tendered' => $tendered !== null ? (string) $tendered : null,
            'change_due' => (string) $change,
            'needs_approval' => $billNeedsApproval || collect($lines)->contains('needs_approval', true),
            'max_discount_percent' => $maxPercent !== null ? (string) $maxPercent : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  Collection<int, Product>  $products
     * @param  array<int, array<int, string>>  $prices
     * @return array<string, mixed>|null
     */
    private function priceLine(array $raw, string $field, Collection $products, array $prices, bool $strict): ?array
    {
        $product = $products->get((int) ($raw['product_id'] ?? 0));

        if ($product === null || ! $product->is_active) {
            return $this->fail($strict, "{$field}.product_id", 'This product is not available for sale.');
        }

        $variantId = isset($raw['variant_id']) && $raw['variant_id'] !== '' ? (int) $raw['variant_id'] : null;
        $variant = $variantId !== null ? $product->variants->firstWhere('id', $variantId) : null;

        if ($variantId !== null && ($variant === null || ! $variant->is_active)) {
            return $this->fail($strict, "{$field}.variant_id", "This variant of {$product->name} is not available.");
        }

        if ($variantId === null && $product->has_variants && $product->variants->where('is_active', true)->isNotEmpty()) {
            return $this->fail($strict, "{$field}.variant_id", "Choose a size or colour of {$product->name}.");
        }

        /** @var ProductUnit|null $productUnit */
        $productUnit = $product->units->firstWhere('unit_id', (int) ($raw['unit_id'] ?? 0));

        if ($productUnit === null) {
            return $this->fail($strict, "{$field}.unit_id", "{$product->name} is not sold in this unit.");
        }

        $qtyText = trim((string) ($raw['qty'] ?? ''));

        if (! is_numeric($qtyText) || ! Qty::of($qtyText)->isPositive() || preg_match('/^\d+(\.\d{1,3})?$/', $qtyText) !== 1) {
            return $this->fail($strict, "{$field}.qty", "Enter a quantity above 0 for {$product->name}.");
        }

        $qty = Qty::of($qtyText);

        if (! $productUnit->unit->allows_decimal && ! $qty->isEqualTo($qty->toScale(0, RoundingMode::Down))) {
            return $this->fail($strict, "{$field}.qty", "{$product->name} is sold in whole {$productUnit->unit->name}s.");
        }

        $unitPrice = $prices[$product->id][$productUnit->unit_id] ?? null;

        if ($unitPrice === null) {
            return $this->fail($strict, "{$field}.unit_id", "{$product->name} has no price per {$productUnit->unit->name}.");
        }

        $unitPrice = Money::of($unitPrice);
        $gross = $qty->multipliedBy($unitPrice)->toScale(2, RoundingMode::HalfUp);
        $taxRate = $product->tax !== null && $product->tax->is_active ? (string) $product->tax->rate : '0';

        return [
            'key' => (string) ($raw['key'] ?? "{$product->id}-".($variantId ?? 0)."-{$productUnit->unit_id}"),
            'product_id' => $product->id,
            'variant_id' => $variantId,
            'unit_id' => $productUnit->unit_id,
            'short_code' => $variant !== null ? $variant->short_code : $product->short_code,
            'name' => $variant !== null ? "{$product->name} {$variant->name}" : $product->name,
            'name_si' => $product->name_si !== null && $variant !== null ? "{$product->name_si} {$variant->name}" : $product->name_si,
            'unit' => $productUnit->unit->symbol,
            'unit_name' => $productUnit->unit->name,
            'unit_si' => $productUnit->unit->name_si,
            'allows_decimal' => $productUnit->unit->allows_decimal,
            'qty' => (string) $qty,
            'factor' => (string) Qty::of($productUnit->factor),
            'base_qty' => (string) $qty->multipliedBy($productUnit->factor)->toScale(Qty::SCALE, RoundingMode::HalfUp),
            'unit_price' => (string) $unitPrice,
            'units' => $product->units->map(fn (ProductUnit $unit) => [
                'id' => $unit->unit_id,
                'symbol' => $unit->unit->symbol,
                'name' => $unit->unit->name,
                'factor' => (string) Qty::of($unit->factor),
                'allows_decimal' => $unit->unit->allows_decimal,
                'price' => $prices[$product->id][$unit->unit_id] ?? null,
            ])->filter(fn (array $unit) => $unit['price'] !== null)->values()->all(),
            '_gross' => $gross,
            '_tax_rate' => $taxRate,
        ];
    }

    /**
     * Approved discount requests referenced by the cart, keyed by id.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $cart
     * @return array<int, ApprovalRequest>
     */
    private function approvals(string $cartUuid, array $lines, array $cart): array
    {
        $ids = array_filter([
            ...array_map(fn (array $line) => (int) ($line['approval_request_id'] ?? 0), $lines),
            (int) ($cart['bill_approval_request_id'] ?? 0),
        ]);

        if ($ids === [] || $cartUuid === '') {
            return [];
        }

        return ApprovalRequest::query()
            ->whereIn('id', $ids)
            ->where('cart_uuid', $cartUuid)
            ->where('type', ApprovalType::Discount)
            ->where('status', ApprovalStatus::Approved)
            ->whereNull('sale_id')
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * Tax contained in a tax-inclusive amount: amount × rate / (100 + rate).
     */
    private function includedTax(BigDecimal $amount, string $rate): BigDecimal
    {
        $rate = BigDecimal::of($rate);

        if (! $rate->isPositive()) {
            return Money::zero();
        }

        return $amount->multipliedBy($rate)->dividedBy($rate->plus(100), 2, RoundingMode::HalfUp);
    }

    private function fail(bool $strict, string $field, string $message): null
    {
        if ($strict) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return null;
    }
}

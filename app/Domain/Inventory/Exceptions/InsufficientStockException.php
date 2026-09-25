<?php

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Catalog\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when stock would go below zero and allow_negative_stock is off.
 * Rendered as a form error ("stock") so the user sees what is missing.
 */
class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly Product $product,
        public readonly string $requested,
        public readonly string $available,
    ) {
        $unit = $product->relationLoaded('baseUnit') ? ' '.$product->baseUnit?->symbol : '';

        parent::__construct(sprintf(
            'Not enough stock of %s %s: %s%s needed, only %s%s available.',
            $product->short_code,
            $product->name,
            self::trim($requested),
            $unit,
            self::trim($available),
            $unit,
        ));
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage(), 'errors' => ['stock' => [$this->getMessage()]]], 422);
        }

        return back()->withInput()->withErrors(['stock' => $this->getMessage()]);
    }

    private static function trim(string $qty): string
    {
        return str_contains($qty, '.') ? rtrim(rtrim($qty, '0'), '.') : $qty;
    }
}

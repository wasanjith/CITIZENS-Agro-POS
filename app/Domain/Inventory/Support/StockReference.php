<?php

namespace App\Domain\Inventory\Support;

/**
 * A document that moves stock (GRN, adjustment, stocktake …). Used to show where a
 * stock movement came from.
 */
interface StockReference
{
    /**
     * Human-facing document number, e.g. "GRN-2026-00012".
     */
    public function referenceLabel(): string;

    /**
     * Page of the document, if there is one.
     */
    public function referenceUrl(): ?string;
}

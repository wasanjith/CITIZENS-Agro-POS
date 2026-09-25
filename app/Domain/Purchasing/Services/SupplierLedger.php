<?php

namespace App\Domain\Purchasing\Services;

use App\Domain\Inventory\Support\StockReference;
use App\Domain\Purchasing\Enums\SupplierLedgerType;
use App\Domain\Purchasing\Models\SupplierLedgerEntry;
use Brick\Math\BigDecimal;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes supplier ledger rows. Credit = the shop owes more, debit = owes less.
 */
class SupplierLedger
{
    public function credit(int $supplierId, SupplierLedgerType $type, Model&StockReference $document, BigDecimal|string $amount, DateTimeInterface $date, ?int $userId, ?string $note = null): SupplierLedgerEntry
    {
        return $this->write($supplierId, $type, $document, '0', (string) $amount, $date, $userId, $note);
    }

    public function debit(int $supplierId, SupplierLedgerType $type, Model&StockReference $document, BigDecimal|string $amount, DateTimeInterface $date, ?int $userId, ?string $note = null): SupplierLedgerEntry
    {
        return $this->write($supplierId, $type, $document, (string) $amount, '0', $date, $userId, $note);
    }

    private function write(int $supplierId, SupplierLedgerType $type, Model&StockReference $document, string $debit, string $credit, DateTimeInterface $date, ?int $userId, ?string $note): SupplierLedgerEntry
    {
        return SupplierLedgerEntry::create([
            'supplier_id' => $supplierId,
            'date' => $date,
            'type' => $type,
            'reference' => $document->referenceLabel(),
            'reference_type' => $document->getMorphClass(),
            'reference_id' => $document->getKey(),
            'debit' => $debit,
            'credit' => $credit,
            'note' => $note,
            'created_by' => $userId,
        ]);
    }
}

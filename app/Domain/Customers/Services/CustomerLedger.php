<?php

namespace App\Domain\Customers\Services;

use App\Domain\Customers\Enums\CustomerLedgerType;
use App\Domain\Customers\Models\CustomerLedgerEntry;
use App\Domain\Inventory\Support\StockReference;
use Brick\Math\BigDecimal;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes customer ledger rows. Debit = the customer owes more, credit = owes less.
 * $document is the source document, or a plain label (e.g. "Opening balance").
 */
class CustomerLedger
{
    public function debit(int $customerId, CustomerLedgerType $type, (Model&StockReference)|string $document, BigDecimal|string $amount, DateTimeInterface $date, ?int $userId, ?DateTimeInterface $dueDate = null, ?string $note = null): CustomerLedgerEntry
    {
        return $this->write($customerId, $type, $document, (string) $amount, '0', $date, $userId, $dueDate, $note);
    }

    public function credit(int $customerId, CustomerLedgerType $type, (Model&StockReference)|string $document, BigDecimal|string $amount, DateTimeInterface $date, ?int $userId, ?string $note = null): CustomerLedgerEntry
    {
        return $this->write($customerId, $type, $document, '0', (string) $amount, $date, $userId, null, $note);
    }

    private function write(int $customerId, CustomerLedgerType $type, (Model&StockReference)|string $document, string $debit, string $credit, DateTimeInterface $date, ?int $userId, ?DateTimeInterface $dueDate, ?string $note): CustomerLedgerEntry
    {
        return CustomerLedgerEntry::create([
            'customer_id' => $customerId,
            'date' => $date,
            'type' => $type,
            'reference' => is_string($document) ? mb_substr($document, 0, 40) : $document->referenceLabel(),
            'reference_type' => is_string($document) ? null : $document->getMorphClass(),
            'reference_id' => is_string($document) ? null : $document->getKey(),
            'debit' => $debit,
            'credit' => $credit,
            'due_date' => $dueDate,
            'note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'created_by' => $userId,
        ]);
    }
}

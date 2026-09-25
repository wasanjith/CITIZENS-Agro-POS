<?php

namespace App\Domain\Purchasing\Enums;

/**
 * draft → submitted → approved → sent → partial → received → closed.
 * A rejected order goes back to its creator; cancelled ends an order that received nothing.
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Sent = 'sent';
    case Partial = 'partial';
    case Received = 'received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Waiting for approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Sent => 'Sent to supplier',
            self::Partial => 'Partly received',
            self::Received => 'Received',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft, self::Closed, self::Cancelled => 'gray',
            self::Submitted => 'amber',
            self::Approved, self::Sent => 'blue',
            self::Partial => 'purple',
            self::Received => 'green',
            self::Rejected => 'red',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    /**
     * Goods can be received against the order.
     */
    public function isReceivable(): bool
    {
        return in_array($this, [self::Approved, self::Sent, self::Partial], true);
    }

    /**
     * Only orders that have not received anything can be cancelled.
     */
    public function isCancellable(): bool
    {
        return in_array($this, [self::Draft, self::Submitted, self::Approved, self::Rejected, self::Sent], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status) => [$status->value => $status->label()])->all();
    }
}

<?php

namespace App\Domain\Customers\Models;

use App\Domain\Customers\Enums\CustomerLedgerType;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Debit = the customer owes more (credit sale, opening balance).
 * Credit = owes less (payment, return to account). Rows are never changed; a mistake
 * is corrected with an ADJUSTMENT row.
 *
 * @property int $id
 * @property int $customer_id
 * @property Carbon $date
 * @property CustomerLedgerType $type
 * @property string $reference
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string $debit
 * @property string $credit
 * @property Carbon|null $due_date
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon $created_at
 */
#[Fillable(['customer_id', 'date', 'type', 'reference', 'reference_type', 'reference_id', 'debit', 'credit', 'due_date', 'note', 'created_by'])]
class CustomerLedgerEntry extends Model
{
    protected $table = 'customer_ledger';

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'date' => 'date',
            'type' => CustomerLedgerType::class,
            'reference_id' => 'integer',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'due_date' => 'date',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo('source', 'reference_type', 'reference_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

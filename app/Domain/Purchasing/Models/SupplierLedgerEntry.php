<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Purchasing\Enums\SupplierLedgerType;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Credit = the shop owes the supplier more (goods received).
 * Debit = owes less (returns, payments).
 *
 * @property int $id
 * @property int $supplier_id
 * @property Carbon $date
 * @property SupplierLedgerType $type
 * @property string $reference
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string $debit
 * @property string $credit
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon $created_at
 */
#[Fillable(['supplier_id', 'date', 'type', 'reference', 'reference_type', 'reference_id', 'debit', 'credit', 'note', 'created_by'])]
class SupplierLedgerEntry extends Model
{
    protected $table = 'supplier_ledger';

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'date' => 'date',
            'type' => SupplierLedgerType::class,
            'reference_id' => 'integer',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
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

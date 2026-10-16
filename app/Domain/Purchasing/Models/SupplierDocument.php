<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Purchasing\Enums\SupplierDocumentType;
use App\Domain\Purchasing\Policies\SupplierDocumentPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A paper in the supplier's file: an invoice, bank deposit slip, receipt or other document,
 * with one photo / PDF per page.
 *
 * @property int $id
 * @property int $supplier_id
 * @property SupplierDocumentType $type
 * @property Carbon $document_date
 * @property string|null $reference
 * @property string|null $amount
 * @property string|null $note
 * @property int|null $uploaded_by
 * @property Carbon|null $created_at
 */
#[Fillable(['supplier_id', 'type', 'document_date', 'reference', 'amount', 'note', 'uploaded_by'])]
#[UsePolicy(SupplierDocumentPolicy::class)]
class SupplierDocument extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'type' => SupplierDocumentType::class,
            'document_date' => 'date',
            'amount' => 'decimal:2',
            'uploaded_by' => 'integer',
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
     * @return HasMany<SupplierDocumentFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(SupplierDocumentFile::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

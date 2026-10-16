<?php

namespace App\Domain\Purchasing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One page (photo or PDF) of a supplier document.
 *
 * @property int $id
 * @property int $supplier_document_id
 * @property string $path
 * @property string|null $original_name
 * @property string $mime_type
 * @property int $size
 * @property Carbon|null $created_at
 */
#[Fillable(['supplier_document_id', 'path', 'original_name', 'mime_type', 'size'])]
class SupplierDocumentFile extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supplier_document_id' => 'integer',
            'size' => 'integer',
        ];
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * The file name as it is stored in the supplier's folder.
     */
    public function fileName(): string
    {
        return basename($this->path);
    }

    /**
     * @return BelongsTo<SupplierDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(SupplierDocument::class, 'supplier_document_id');
    }
}

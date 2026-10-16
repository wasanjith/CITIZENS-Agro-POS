<?php

namespace App\Domain\Purchasing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A photo (or PDF) of the supplier's invoice, attached to a goods receipt.
 *
 * @property int $id
 * @property int $goods_receipt_id
 * @property string $path
 * @property string|null $original_name
 * @property string $mime_type
 * @property int $size
 * @property int|null $uploaded_by
 * @property Carbon|null $created_at
 */
#[Fillable(['goods_receipt_id', 'path', 'original_name', 'mime_type', 'size', 'uploaded_by'])]
class GoodsReceiptInvoice extends Model
{
    /** Files per upload. */
    public const MAX_FILES = 10;

    /** Size limit per file, in KB. Phone photos are shrunk in the browser first. */
    public const MAX_KB = 10240;

    /**
     * Validation rules for the uploaded files under $field.
     *
     * @return array<string, list<string>>
     */
    public static function uploadRules(string $field, bool $required = false): array
    {
        return [
            $field => [$required ? 'required' : 'nullable', 'array', 'max:'.self::MAX_FILES],
            "{$field}.*" => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.self::MAX_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'goods_receipt_id' => 'integer',
            'size' => 'integer',
            'uploaded_by' => 'integer',
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
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

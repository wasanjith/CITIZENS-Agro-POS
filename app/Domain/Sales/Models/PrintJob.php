<?php

namespace App\Domain\Sales\Models;

use App\Domain\Identity\Models\Printer;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One print or reprint on a thermal printer. printed_at is set when the terminal
 * reports that the browser sent the page to its printer.
 *
 * @property int $id
 * @property int|null $terminal_id
 * @property int|null $printer_id
 * @property int|null $user_id
 * @property PrintDocumentType $document_type
 * @property int|null $document_id
 * @property bool $is_copy
 * @property Carbon|null $printed_at
 * @property Carbon $created_at
 */
#[Fillable(['terminal_id', 'printer_id', 'user_id', 'document_type', 'document_id', 'is_copy', 'printed_at', 'created_at'])]
class PrintJob extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'terminal_id' => 'integer',
            'printer_id' => 'integer',
            'user_id' => 'integer',
            'document_type' => PrintDocumentType::class,
            'document_id' => 'integer',
            'is_copy' => 'boolean',
            'printed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /**
     * @return BelongsTo<Printer, $this>
     */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(PrintDocumentType $type, ?int $documentId, ?Terminal $terminal, ?int $userId, bool $isCopy = false): self
    {
        return self::create([
            'terminal_id' => $terminal?->id,
            'printer_id' => $terminal?->printer?->id,
            'user_id' => $userId,
            'document_type' => $type,
            'document_id' => $documentId,
            'is_copy' => $isCopy,
            'created_at' => now(),
        ]);
    }
}

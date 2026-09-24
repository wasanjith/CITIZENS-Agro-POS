<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Policies\PrinterPolicy;
use Database\Factories\PrinterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A USB thermal printer, attached to at most one terminal.
 *
 * @property int $id
 * @property string $name
 * @property int|null $terminal_id
 * @property string|null $windows_name
 * @property string|null $model
 * @property int $paper_width_mm
 * @property int $dpi
 * @property bool $has_cash_drawer
 * @property bool $is_active
 * @property Carbon|null $last_test_at
 */
#[Fillable(['name', 'terminal_id', 'windows_name', 'model', 'paper_width_mm', 'dpi', 'has_cash_drawer', 'is_active'])]
#[UseFactory(PrinterFactory::class)]
#[UsePolicy(PrinterPolicy::class)]
class Printer extends Model
{
    /** @use HasFactory<PrinterFactory> */
    use HasFactory, LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paper_width_mm' => 'integer',
            'dpi' => 'integer',
            'has_cash_drawer' => 'boolean',
            'is_active' => 'boolean',
            'last_test_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'terminal_id', 'windows_name', 'model', 'paper_width_mm', 'dpi', 'has_cash_drawer', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }
}

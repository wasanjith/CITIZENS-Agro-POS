<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Policies\UnitPolicy;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Unit of measure: kg, bag, packet, piece …
 *
 * @property int $id
 * @property string $name
 * @property string|null $name_si
 * @property string $symbol
 * @property bool $allows_decimal
 */
#[Fillable(['name', 'name_si', 'symbol', 'allows_decimal'])]
#[UseFactory(UnitFactory::class)]
#[UsePolicy(UnitPolicy::class)]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory, LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allows_decimal' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'name_si', 'symbol', 'allows_decimal'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}

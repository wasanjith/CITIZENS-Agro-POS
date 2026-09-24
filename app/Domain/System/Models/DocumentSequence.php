<?php

namespace App\Domain\System\Models;

use App\Domain\System\Enums\SequenceResetPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $type
 * @property string $prefix
 * @property int $next_number
 * @property int $padding
 * @property SequenceResetPeriod $reset_period
 * @property string|null $period_key
 */
#[Fillable(['type', 'prefix', 'next_number', 'padding', 'reset_period', 'period_key'])]
class DocumentSequence extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'next_number' => 'integer',
            'padding' => 'integer',
            'reset_period' => SequenceResetPeriod::class,
        ];
    }
}

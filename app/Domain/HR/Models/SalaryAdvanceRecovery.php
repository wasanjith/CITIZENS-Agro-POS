<?php

namespace App\Domain\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $salary_advance_id
 * @property int $payslip_id
 * @property string $amount
 */
#[Fillable(['salary_advance_id', 'payslip_id', 'amount'])]
class SalaryAdvanceRecovery extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'salary_advance_id' => 'integer',
            'payslip_id' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<SalaryAdvance, $this>
     */
    public function advance(): BelongsTo
    {
        return $this->belongsTo(SalaryAdvance::class, 'salary_advance_id');
    }

    /**
     * @return BelongsTo<Payslip, $this>
     */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}

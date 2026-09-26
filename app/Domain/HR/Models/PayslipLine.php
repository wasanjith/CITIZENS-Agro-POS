<?php

namespace App\Domain\HR\Models;

use App\Domain\HR\Enums\SalaryComponentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $payslip_id
 * @property string $component_name
 * @property SalaryComponentType $type
 * @property string $amount
 * @property bool $is_epf_applicable
 * @property bool $is_manual
 */
#[Fillable(['payslip_id', 'component_name', 'type', 'amount', 'is_epf_applicable', 'is_manual'])]
class PayslipLine extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payslip_id' => 'integer',
            'type' => SalaryComponentType::class,
            'amount' => 'decimal:2',
            'is_epf_applicable' => 'boolean',
            'is_manual' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Payslip, $this>
     */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}

<?php

namespace App\Domain\Customers\Models;

use App\Domain\Sales\Models\Sale;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part of a customer payment applied to one credit invoice.
 *
 * @property int $id
 * @property int $customer_payment_id
 * @property int $sale_id
 * @property string $amount
 */
#[Fillable(['customer_payment_id', 'sale_id', 'amount'])]
class CustomerPaymentAllocation extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_payment_id' => 'integer',
            'sale_id' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<CustomerPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'customer_payment_id');
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}

<?php

namespace App\Domain\Sales\Services;

use App\Domain\Identity\Enums\Role;
use App\Domain\System\Services\Settings;
use App\Models\User;
use Brick\Math\BigDecimal;

/**
 * How much discount a user may give without the cashier's approval.
 */
class DiscountLimits
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Maximum discount in percent, or null for no limit (Super Admin, or a delegate
     * holding pos.discount.override).
     */
    public function maxPercentFor(User $user): ?BigDecimal
    {
        if ($user->can('pos.discount.override')) {
            return null;
        }

        if (! $user->can('pos.discount.basic')) {
            return BigDecimal::of('0');
        }

        $key = $user->hasRole(Role::Manager->value) ? 'pos.max_discount_percent_manager' : 'pos.max_discount_percent_sales_staff';

        return BigDecimal::of((string) $this->settings->get($key, 0));
    }

    public function allows(User $user, BigDecimal $percent): bool
    {
        $max = $this->maxPercentFor($user);

        return $max === null || $percent->isLessThanOrEqualTo($max);
    }
}

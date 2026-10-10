<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Identity\Models\Terminal;
use App\Domain\Sales\Enums\ApprovalStatus;
use App\Domain\Sales\Enums\ApprovalType;
use App\Domain\Sales\Events\ApprovalRequested;
use App\Domain\Sales\Models\ApprovalRequest;
use App\Domain\Sales\Services\LiveCartStore;
use App\Domain\Sales\Support\LiveBroadcast;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A counter asks the cashier to allow a discount above the staff limit. Printing is
 * blocked until it is approved (or the discount is removed). Asking again for the same
 * line replaces the earlier request.
 *
 * The line or bill amount (and so the % the cashier sees) and the label come from the
 * cart as last priced on the server (LiveCartStore), never from the request, so a
 * tampered request cannot make a large discount look small.
 */
class RequestDiscountApprovalAction
{
    public function __construct(private readonly LiveCartStore $store) {}

    /**
     * @param  array{scope: string, line_key?: string|null, amount: string}  $data
     */
    public function handle(Terminal $terminal, User $user, string $cartUuid, array $data): ApprovalRequest
    {
        $amount = Money::of($data['amount']);
        $lineKey = $data['scope'] === 'line' ? ($data['line_key'] ?? null) : null;
        [$gross, $label] = $this->priced($terminal, $cartUuid, $data['scope'], $lineKey);

        if ($amount->isGreaterThan($gross)) {
            throw ValidationException::withMessages(['amount' => 'The discount is more than the amount it is given on.']);
        }

        $request = DB::transaction(function () use ($terminal, $user, $cartUuid, $data, $amount, $gross, $label, $lineKey): ApprovalRequest {
            ApprovalRequest::query()
                ->pending()
                ->where('cart_uuid', $cartUuid)
                ->where('type', ApprovalType::Discount)
                ->where('payload->scope', $data['scope'])
                ->when($lineKey !== null, fn ($query) => $query->where('payload->line_key', $lineKey))
                ->get()
                ->each(fn (ApprovalRequest $old) => $old->forceFill([
                    'status' => ApprovalStatus::Rejected,
                    'decided_at' => now(),
                    'decision_note' => 'Replaced by a new request',
                ])->save());

            return ApprovalRequest::create([
                'type' => ApprovalType::Discount,
                'cart_uuid' => $cartUuid,
                'terminal_id' => $terminal->id,
                'requested_by' => $user->id,
                'status' => ApprovalStatus::Pending,
                'payload' => [
                    'scope' => $data['scope'],
                    'line_key' => $lineKey,
                    'label' => mb_substr($label, 0, 191),
                    'amount' => (string) $amount,
                    'gross' => (string) $gross,
                    'percent' => (string) Money::percent($amount, $gross),
                ],
            ]);
        });

        LiveBroadcast::send(new ApprovalRequested($request->toBroadcast()));

        return $request;
    }

    /**
     * Gross amount and label of the line (or the bill) in this terminal's synced cart.
     *
     * @return array{0: BigDecimal, 1: string}
     */
    private function priced(Terminal $terminal, string $cartUuid, string $scope, ?string $lineKey): array
    {
        $cart = $this->store->get($terminal->id);

        if ($cart === null || ($cart['cart_uuid'] ?? null) !== $cartUuid) {
            throw ValidationException::withMessages(['cart_uuid' => 'The bill is still being saved. Ask again in a moment.']);
        }

        if ($scope === 'bill') {
            return [Money::of($cart['subtotal'] ?? '0')->minus(Money::of($cart['line_discount_total'] ?? '0')), 'Bill discount'];
        }

        foreach (is_array($cart['lines'] ?? null) ? $cart['lines'] : [] as $line) {
            if (is_array($line) && ($line['key'] ?? null) === $lineKey) {
                return [Money::of((string) $line['gross']), trim(($line['short_code'] ?? '').' '.($line['name'] ?? ''))];
            }
        }

        throw ValidationException::withMessages(['line_key' => 'This item is not on the bill yet. Ask again in a moment.']);
    }
}

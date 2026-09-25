<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Identity\Models\Terminal;
use App\Domain\Sales\Enums\ApprovalStatus;
use App\Domain\Sales\Enums\ApprovalType;
use App\Domain\Sales\Events\ApprovalRequested;
use App\Domain\Sales\Models\ApprovalRequest;
use App\Domain\Sales\Support\LiveBroadcast;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A counter asks the cashier to allow a discount above the staff limit. Printing is
 * blocked until it is approved (or the discount is removed). Asking again for the same
 * line replaces the earlier request.
 */
class RequestDiscountApprovalAction
{
    /**
     * @param  array{scope: string, line_key?: string|null, label: string, amount: string, gross: string}  $data
     */
    public function handle(Terminal $terminal, User $user, string $cartUuid, array $data): ApprovalRequest
    {
        $amount = Money::of($data['amount']);
        $gross = Money::of($data['gross']);
        $lineKey = $data['scope'] === 'line' ? ($data['line_key'] ?? null) : null;

        $request = DB::transaction(function () use ($terminal, $user, $cartUuid, $data, $amount, $gross, $lineKey): ApprovalRequest {
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
                    'label' => mb_substr($data['label'], 0, 191),
                    'amount' => (string) $amount,
                    'gross' => (string) $gross,
                    'percent' => (string) Money::percent($amount, $gross),
                ],
            ]);
        });

        LiveBroadcast::send(new ApprovalRequested($request->toBroadcast()));

        return $request;
    }
}

<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\ApprovalStatus;
use App\Domain\Sales\Events\ApprovalDecided;
use App\Domain\Sales\Models\ApprovalRequest;
use App\Domain\Sales\Support\LiveBroadcast;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The cashier approves or rejects a counter's request (banner on the cashier screen).
 */
class DecideApprovalAction
{
    public function handle(ApprovalRequest $request, User $user, bool $approve, ?string $note = null): ApprovalRequest
    {
        $request = DB::transaction(function () use ($request, $user, $approve, $note): ApprovalRequest {
            $request = ApprovalRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($request->status !== ApprovalStatus::Pending) {
                throw ValidationException::withMessages(['request' => 'This request was already '.strtolower($request->status->label()).'.']);
            }

            $request->forceFill([
                'status' => $approve ? ApprovalStatus::Approved : ApprovalStatus::Rejected,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'decision_note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
            ])->save();

            return $request;
        });

        LiveBroadcast::send(new ApprovalDecided($request->terminal_id, $request->toBroadcast()));

        return $request;
    }
}

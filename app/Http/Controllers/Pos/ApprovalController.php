<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Sales\Actions\DecideApprovalAction;
use App\Domain\Sales\Actions\RequestDiscountApprovalAction;
use App\Domain\Sales\Models\ApprovalRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Discount approval: the counter asks, the cashier approves or rejects.
 */
class ApprovalController extends Controller
{
    public function store(Request $request, CurrentTerminal $currentTerminal, RequestDiscountApprovalAction $requestApproval): JsonResponse
    {
        $validated = $request->validate([
            'cart_uuid' => ['required', 'uuid'],
            'scope' => ['required', 'in:line,bill'],
            'line_key' => ['required_if:scope,line', 'nullable', 'string', 'max:64'],
            'label' => ['required', 'string', 'max:191'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'gross' => ['required', 'numeric', 'gt:0', 'max:99999999'],
        ]);

        $approval = $requestApproval->handle($currentTerminal->get(), $request->user(), $validated['cart_uuid'], [
            'scope' => $validated['scope'],
            'line_key' => $validated['line_key'] ?? null,
            'label' => $validated['label'],
            'amount' => (string) $validated['amount'],
            'gross' => (string) $validated['gross'],
        ]);

        return response()->json(['approval' => $approval->toBroadcast()], 201);
    }

    public function approve(Request $request, ApprovalRequest $approvalRequest, DecideApprovalAction $decide): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;

        return response()->json(['approval' => $decide->handle($approvalRequest, $request->user(), true, $note)->toBroadcast()]);
    }

    public function reject(Request $request, ApprovalRequest $approvalRequest, DecideApprovalAction $decide): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;

        return response()->json(['approval' => $decide->handle($approvalRequest, $request->user(), false, $note)->toBroadcast()]);
    }
}

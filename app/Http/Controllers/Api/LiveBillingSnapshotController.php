<?php

namespace App\Http\Controllers\Api;

use App\Domain\Sales\Services\LiveBillingSnapshot;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/live-billing/snapshot: first paint of Live Billing, and its 3-second
 * fallback while the WebSocket connection is down.
 */
class LiveBillingSnapshotController extends Controller
{
    public function __invoke(LiveBillingSnapshot $snapshot): JsonResponse
    {
        return response()->json($snapshot->build());
    }
}

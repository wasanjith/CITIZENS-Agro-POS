<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Identity\Models\Terminal;
use App\Domain\Sales\Enums\CounterEventType;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Events\CounterActivity;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Services\CounterEventRecorder;
use App\Domain\Sales\Support\LiveBroadcast;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * F10 / "Reprint": print an invoice again. Always marked "පිටපත / COPY", counted,
 * logged as REPRINTED and recorded as a copy in the print log.
 */
class ReprintInvoiceAction
{
    public function __construct(private readonly CounterEventRecorder $recorder) {}

    public function handle(Sale $sale, User $user, ?Terminal $terminal): PrintJob
    {
        if ($sale->invoice_no === null) {
            throw ValidationException::withMessages(['sale' => 'A held bill has no invoice to print.']);
        }

        [$job, $event] = DB::transaction(function () use ($sale, $user, $terminal): array {
            $sale->increment('print_count');

            $event = $this->recorder->record($terminal->id ?? $sale->invoiced_terminal_id, $user->id, CounterEventType::Reprinted, null, [
                'copy' => $sale->print_count - 1,
            ], $sale);

            activity()->performedOn($sale)->causedBy($user)->event('reprinted')
                ->withProperties(['print_count' => $sale->print_count, 'terminal' => $terminal?->code])
                ->log("Invoice {$sale->invoice_no} reprinted");

            return [PrintJob::record(PrintDocumentType::Invoice, $sale->id, $terminal?->loadMissing('printer'), $user->id, isCopy: true), $event];
        });

        LiveBroadcast::send(new CounterActivity($terminal->id ?? $sale->invoiced_terminal_id, [$event->toBroadcast()]));

        return $job;
    }
}

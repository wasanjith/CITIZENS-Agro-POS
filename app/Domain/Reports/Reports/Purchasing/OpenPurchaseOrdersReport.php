<?php

namespace App\Domain\Reports\Reports\Purchasing;

use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Reports\Report;
use App\Domain\Reports\Services\ReportLookups;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\Filter;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Purchase orders not yet fully received: waiting for approval, approved, sent or partly received.
 */
class OpenPurchaseOrdersReport extends Report
{
    public const OPEN = [PurchaseOrderStatus::Submitted, PurchaseOrderStatus::Approved, PurchaseOrderStatus::Sent, PurchaseOrderStatus::Partial];

    public function __construct(private readonly ReportLookups $lookups) {}

    public function key(): string
    {
        return 'open-purchase-orders';
    }

    public function title(): string
    {
        return 'Open purchase orders';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Purchasing;
    }

    public function description(): string
    {
        return 'Orders waiting for approval, approved, sent or partly received, with how much has arrived.';
    }

    public function permission(): array
    {
        return ['purchasing.po.approve', 'purchasing.po.create'];
    }

    public function usesPeriod(): bool
    {
        return false;
    }

    public function filters(User $user): array
    {
        return [
            Filter::select('supplier', 'Supplier', $this->lookups->suppliers()),
            Filter::select('status', 'Status', collect(self::OPEN)->mapWithKeys(fn (PurchaseOrderStatus $status) => [$status->value => $status->label()])->all()),
        ];
    }

    public function columns(ReportInput $input): array
    {
        return [
            Column::text('number', 'Order'),
            Column::text('supplier', 'Supplier'),
            Column::date('order_date', 'Ordered'),
            Column::date('expected_date', 'Expected'),
            Column::text('status', 'Status'),
            Column::int('lines', 'Lines', false),
            Column::percent('received', 'Received'),
            ...($input->user->can('catalog.cost.view') ? [Column::money('total', 'Order value')] : []),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $orders = PurchaseOrder::query()
            ->whereIn('status', $input->get('status') !== null ? [$input->get('status')] : self::OPEN)
            ->when($input->get('supplier'), fn ($query, $supplier) => $query->where('supplier_id', (int) $supplier))
            ->orderBy('order_date')
            ->get();

        $progress = DB::table('po_lines')
            ->whereIn('purchase_order_id', $orders->pluck('id'))
            ->groupBy('purchase_order_id')
            ->selectRaw('purchase_order_id, COUNT(*) AS line_count, SUM(LEAST(received_base_qty, base_qty)) / NULLIF(SUM(base_qty), 0) * 100 AS received')
            ->get()
            ->keyBy('purchase_order_id');

        $rows = $orders->map(fn (PurchaseOrder $order) => [
            'number' => $order->number,
            'supplier' => $this->lookups->suppliers()[$order->supplier_id] ?? '',
            'order_date' => $order->order_date,
            'expected_date' => $order->expected_date,
            'status' => $order->status->label(),
            'lines' => (int) ($progress[$order->id]->line_count ?? 0),
            'received' => (string) round((float) ($progress[$order->id]->received ?? 0), 1),
            'total' => $order->total,
            '_url' => route('purchasing.purchase-orders.show', $order->id),
            '_alert' => $order->expected_date !== null && $order->expected_date->lt(today()),
        ])->all();

        return new ReportResult($rows, ['Open orders' => number_format(count($rows))], ['Red: the expected date has passed.']);
    }
}

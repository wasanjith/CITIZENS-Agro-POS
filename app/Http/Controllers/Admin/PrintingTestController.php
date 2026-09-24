<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\Printer;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\System\Services\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Phase 0.4 printing spike: proves Sinhala invoices print on the real thermal
 * printer model and that the cash drawer opens through QZ Tray.
 */
class PrintingTestController extends Controller
{
    public function index(CurrentTerminal $currentTerminal): View
    {
        $this->authorize('viewAny', Printer::class);

        $terminal = $currentTerminal->get();

        return view('admin.printing-test.index', [
            'terminal' => $terminal,
            'printer' => $terminal?->printer,
        ]);
    }

    /**
     * 80 mm thermal invoice with Sinhala text, printed by the browser as an image.
     */
    public function thermal(Request $request, Settings $settings, CurrentTerminal $currentTerminal): View
    {
        $this->authorize('viewAny', Printer::class);

        $printer = $currentTerminal->get()?->printer;

        if ($request->boolean('autoprint') && $printer !== null) {
            $printer->forceFill(['last_test_at' => now()])->save();
        }

        return view('print.invoice-sample', [
            ...$this->sampleInvoice($request, $settings),
            'autoprint' => $request->boolean('autoprint'),
            'paperWidth' => $printer->paper_width_mm ?? 80,
        ]);
    }

    /**
     * The same invoice as an A4 PDF, rendered by headless Chrome.
     */
    public function pdf(Request $request, Settings $settings): PdfBuilder
    {
        $this->authorize('viewAny', Printer::class);

        return Pdf::view('print.invoice-sample-a4', $this->sampleInvoice($request, $settings))
            ->format('a4')
            ->name('sample-invoice.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleInvoice(Request $request, Settings $settings): array
    {
        $language = in_array($request->query('lang'), ['si', 'en', 'si+en'], true)
            ? $request->query('lang')
            : $settings->get('receipt.language', 'si');

        $items = [
            ['name' => 'Urea Fertilizer 50kg', 'name_si' => 'යූරියා පොහොර 50kg', 'qty' => 2, 'unit' => 'bag', 'unit_si' => 'බෑග්', 'price' => 9500.00],
            ['name' => 'Paddy Seed BG 352 1kg', 'name_si' => 'වී බීජ BG 352 1kg', 'qty' => 5, 'unit' => 'packet', 'unit_si' => 'පැකට්', 'price' => 450.00],
            ['name' => 'Triple Super Phosphate 1kg', 'name_si' => 'ත්‍රිත්ව සුපර් පොස්පේට් 1kg', 'qty' => 3.5, 'unit' => 'kg', 'unit_si' => 'කි.ග්‍රෑ.', 'price' => 180.00],
            ['name' => 'Insecticide Spray 100ml', 'name_si' => 'කෘමිනාශක ස්ප්‍රේ 100ml', 'qty' => 1, 'unit' => 'bottle', 'unit_si' => 'බෝතල්', 'price' => 1250.00],
            ['name' => 'Bicycle Tube 26"', 'name_si' => 'බයිසිකල් ටියුබ් 26"', 'qty' => 2, 'unit' => 'piece', 'unit_si' => 'කෑලි', 'price' => 650.00],
        ];

        foreach ($items as &$item) {
            $item['total'] = round($item['qty'] * $item['price'], 2);
        }
        unset($item);

        $subtotal = array_sum(array_column($items, 'total'));
        $discount = 250.00;
        $total = $subtotal - $discount;
        $tendered = ceil($total / 1000) * 1000;

        return [
            'language' => $language,
            'shop' => $settings->group('shop'),
            'receipt' => $settings->group('receipt'),
            'invoice' => [
                'number' => 'INV-'.now()->format('Y').'-000000',
                'date' => now(),
                'counter' => 2,
                'staff' => $request->user()->name,
                'items' => $items,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'tendered' => $tendered,
                'balance' => $tendered - $total,
                'is_sample' => true,
            ],
        ];
    }
}

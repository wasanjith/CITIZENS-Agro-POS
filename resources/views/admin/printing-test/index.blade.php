@extends('layouts.app')

@section('title', 'Printing test')

@push('head')
    @vite('resources/js/printing/drawer.js')
@endpush

@section('content')
    <x-ui.page-header title="Printing test" description="Phase 0 check: a Sinhala invoice prints on the thermal printer and the cash drawer opens." />

    @if (! $terminal)
        <x-ui.alert type="warning" class="mb-6">
            This browser is not a registered terminal, so it has no printer assigned. The sample prints to this PC's default printer.
            Register the PC on the <a href="{{ route('admin.terminals.index') }}" class="font-semibold underline">Terminals</a> page first.
        </x-ui.alert>
    @elseif (! $printer)
        <x-ui.alert type="warning" class="mb-6">{{ $terminal->displayName() }} has no printer assigned. Set one on the <a href="{{ route('admin.printers.index') }}" class="font-semibold underline">Printers</a> page.</x-ui.alert>
    @endif

    <div
        class="grid gap-6 lg:grid-cols-2"
        x-data="{
            printing: false,
            drawerStatus: '',
            print(lang) {
                this.printing = true;
                const frame = this.$refs.frame;
                frame.src = @js(route('admin.printing-test.thermal')) + '?autoprint=1&lang=' + encodeURIComponent(lang) + '&t=' + Date.now();
                setTimeout(() => this.printing = false, 4000);
            },
            async openDrawer() {
                this.drawerStatus = 'Connecting to QZ Tray…';
                try {
                    const printer = await window.CitizensPrinting.openCashDrawer(@js($printer?->windows_name));
                    this.drawerStatus = 'Drawer command sent to ' + printer + '.';
                } catch (error) {
                    this.drawerStatus = 'Failed: ' + (error?.message ?? error) + '. Is QZ Tray running on this PC?';
                }
            },
        }"
    >
        <x-ui.card title="1. Sinhala invoice (80 mm)" description="Prints silently when Chrome is started with --kiosk-printing; otherwise the print dialog opens.">
            <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-500">Terminal</dt><dd>{{ $terminal?->displayName() ?? 'Not registered' }}</dd>
                <dt class="text-gray-500">Printer</dt><dd>{{ $printer?->name ?? '—' }}</dd>
                <dt class="text-gray-500">Paper</dt><dd>{{ $printer?->paper_width_mm ?? 80 }} mm</dd>
                <dt class="text-gray-500">Last test</dt><dd>{{ $printer?->last_test_at?->diffForHumans() ?? 'Never' }}</dd>
            </dl>

            <div class="mt-4 flex flex-wrap gap-2">
                <x-ui.button x-on:click="print('si')" x-bind:disabled="printing">Print Sinhala sample</x-ui.button>
                <x-ui.button variant="secondary" x-on:click="print('si+en')" x-bind:disabled="printing">Sinhala + English</x-ui.button>
                <x-ui.button variant="secondary" :href="route('admin.printing-test.thermal')" target="_blank">Preview</x-ui.button>
            </div>

            <p class="mt-4 text-xs text-gray-500">
                Check on paper: ශ්‍රී, ක්‍ෂ, ප්‍ර and ත්‍රි are joined correctly, numbers line up, nothing is cut on the right, the cutter cuts after the footer.
            </p>
        </x-ui.card>

        <x-ui.card title="2. Cash drawer (main cashier only)" description="Sends the ESC/POS drawer command through QZ Tray. No paper is printed.">
            <p class="text-sm text-gray-600">
                Windows printer: <strong>{{ $printer?->windows_name ?: 'default printer' }}</strong>
                @if ($printer && ! $printer->has_cash_drawer)
                    <span class="text-amber-700">(this printer is not marked as having a drawer)</span>
                @endif
            </p>
            <x-ui.button class="mt-4" x-on:click="openDrawer()">Open cash drawer</x-ui.button>
            <p class="mt-3 text-sm" x-text="drawerStatus"></p>
        </x-ui.card>

        <x-ui.card title="3. A4 PDF (Sinhala)" description="Rendered by headless Chrome on the server. Used for A4 invoices, POs and payslips.">
            <x-ui.button variant="secondary" :href="route('admin.printing-test.pdf')" target="_blank">Download sample PDF</x-ui.button>
        </x-ui.card>

        <x-ui.card title="Chrome setup on each terminal">
            <ol class="list-inside list-decimal space-y-1 text-sm text-gray-600">
                <li>Set the thermal printer as the <strong>Windows default printer</strong>, paper 80 mm, margins 0.</li>
                <li>Start Chrome with <code class="rounded bg-gray-100 px-1">--kiosk --kiosk-printing</code> and the POS address.</li>
                <li>Main cashier only: install QZ Tray and allow this site.</li>
                <li>Write the working settings into <code class="rounded bg-gray-100 px-1">docs/PRINTING.md</code>.</li>
            </ol>
        </x-ui.card>

        <iframe x-ref="frame" class="hidden" title="Print frame"></iframe>
    </div>
@endsection

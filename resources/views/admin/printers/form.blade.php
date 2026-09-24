@extends('layouts.app')

@php $isNew = ! $printer->exists; @endphp

@section('title', $isNew ? 'Add printer' : 'Edit '.$printer->name)

@section('content')
    <x-ui.page-header :title="$isNew ? 'Add printer' : 'Edit printer'" />

    <form method="POST" action="{{ $isNew ? route('admin.printers.store') : route('admin.printers.update', $printer) }}">
        @csrf
        @unless ($isNew)
            @method('PUT')
        @endunless

        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="name" label="Name" :value="$printer->name" hint="e.g. Printer #1 (Counter 1)" required />
                <x-ui.select
                    name="terminal_id"
                    label="Terminal"
                    :options="$terminals->mapWithKeys(fn ($terminal) => [$terminal->id => $terminal->name])->all()"
                    :value="$printer->terminal_id"
                    placeholder="Spare (not assigned)"
                />
                <x-ui.input name="windows_name" label="Windows printer name" :value="$printer->windows_name" hint="Exactly as shown in Windows 'Printers & scanners'. Needed for the cash drawer." />
                <x-ui.input name="model" label="Model" :value="$printer->model" hint="e.g. Epson TM-T82X" />
                <x-ui.select name="paper_width_mm" label="Paper width" :options="['80' => '80 mm', '58' => '58 mm']" :value="$printer->paper_width_mm" required />
                <x-ui.input name="dpi" label="Resolution (dpi)" type="number" :value="$printer->dpi" required />
                <x-ui.checkbox name="has_cash_drawer" label="Cash drawer connected" :checked="$printer->has_cash_drawer" hint="Only the main cashier printer." />
                <x-ui.checkbox name="is_active" label="Active" :checked="$printer->is_active" />
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="route('admin.printers.index')">Cancel</x-ui.button>
                <x-ui.button type="submit">{{ $isNew ? 'Add printer' : 'Save changes' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection

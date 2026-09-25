@extends('layouts.pos')

@section('title', 'Cashier')
@section('pos-main-class', 'overflow-hidden')

@push('head')
    @vite('resources/js/printing/drawer.js')
@endpush

@section('pos-title')
    <span class="hidden text-xs text-brand-100 lg:inline">Drawer opened {{ $session->opened_at->format('H:i') }} · float Rs. {{ number_format((float) $session->opening_float, 2) }}</span>
@endsection

@section('pos-status')
    <span class="hidden items-center gap-1 sm:flex">
        @can('customers.credit.manage')
            <a href="{{ route('pos.customer-payments.create') }}" class="rounded bg-white/10 px-2 py-1 text-xs hover:bg-white/20">Customer payment</a>
        @endcan
        @can('pos.refund')
            <a href="{{ route('pos.returns.create') }}" class="rounded bg-white/10 px-2 py-1 text-xs hover:bg-white/20">Return</a>
        @endcan
        @can('drawer.handover')
            <a href="{{ route('pos.handover.create') }}" class="rounded bg-white/10 px-2 py-1 text-xs hover:bg-white/20">Hand over</a>
        @else
            <a href="{{ route('pos.handover.return') }}" class="rounded bg-white/10 px-2 py-1 text-xs hover:bg-white/20">Hand back</a>
        @endcan
        <a href="{{ route('pos.drawer.close') }}" class="rounded bg-white/10 px-2 py-1 text-xs hover:bg-white/20">Close day</a>
    </span>
@endsection

@section('content')
    <div class="h-full p-3">
        @include('pos.partials.live-billing')
    </div>
@endsection

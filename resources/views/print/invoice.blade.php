{{-- 80 mm counter invoice. Printed by the browser on the counter's own thermal printer. --}}
@extends('layouts.print', ['htmlLang' => $language === 'en' ? 'en' : 'si'])

@section('title', $invoice['number'])

@section('content')
    @include('print.partials.invoice-body')
@endsection

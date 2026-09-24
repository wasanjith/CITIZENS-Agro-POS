@extends('layouts.print', ['htmlLang' => $language === 'en' ? 'en' : 'si'])

@section('title', 'Sample invoice')

@section('content')
    @include('print.partials.invoice-body')
@endsection

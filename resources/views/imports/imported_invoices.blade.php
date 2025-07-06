@extends('layouts.app')
@section('content')
<div class="container">
    <h1>Imported Invoices</h1>
    <p>The following invoices were generated from your import:</p>
    <ul class="list-group mb-4">
        @foreach($invoices as $invoice)
            <li class="list-group-item">
                <a href="{{ route('invoices.show', $invoice) }}">Invoice #{{ $invoice->id }} - {{ $invoice->client->name ?? 'N/A' }} - {{ $invoice->total }} LEK</a>
            </li>
        @endforeach
    </ul>
    <a href="{{ route('imports.index') }}" class="btn btn-secondary">Back to Uploads</a>
</div>
@endsection 
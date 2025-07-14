@extends('layouts.app')
@section('content')
<div class="container">
    <h1>Imported Invoices</h1>
    <p>The following invoices were generated from your import:</p>
    <ul class="list-group mb-4">
        @forelse($invoices as $invoice)
            <li class="list-group-item">
                Invoice #{{ $invoice->number ?? $invoice->id }} - {{ $invoice->client->name ?? 'N/A' }} - {{ $invoice->total }} LEK
                @if($invoice->fiscalization_url)
                    <br>
                    <a href="{{ $invoice->fiscalization_url }}" target="_blank" class="btn btn-success btn-sm mt-2">
                        Fiscalization Link
                    </a>
                @else
                    <span class="text-danger">Not fiscalized</span>
                    @if($invoice->fiscalization_response)
                        <br>
                        <span class="text-muted">Reason: {{ $invoice->fiscalization_response }}</span>
                    @endif
                @endif
            </li>
        @empty
            <li class="list-group-item text-warning">No invoices were created from your import.</li>
        @endforelse
    </ul>
    <a href="{{ route('imports.index') }}" class="btn btn-secondary">Back to Uploads</a>
</div>
@endsection 
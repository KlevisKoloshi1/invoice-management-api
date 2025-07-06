@extends('layouts.app')
@section('content')
<div class="container">
    <h1>Invoice {{ $invoice->id }}{{ $invoice->cr ? '/' . date('Y', strtotime($invoice->created_at)) . '/' . $invoice->cr : '' }}</h1>
    <div class="mb-3">
        <strong>Amount:</strong> {{ number_format($invoice->total, 2) }} LEK<br>
        <strong>Before VAT:</strong> {{ number_format($invoice->total / 1.2, 2) }} LEK<br>
        <strong>VAT amount:</strong> {{ number_format($invoice->total - ($invoice->total / 1.2), 2) }} LEK<br>
        <strong>Date:</strong> {{ $invoice->created_at->format('d/m/Y H:i') }}<br>
        <strong>Business unit:</strong> {{ $invoice->bu }}<br>
        <strong>Issuer Tax Number:</strong> {{ $invoice->tin }}<br>
        <strong>IIC:</strong> {{ $invoice->iic }}<br>
        <strong>FIC:</strong> {{ $invoice->fic }}<br>
        <strong>Invoice type:</strong> Cash invoice<br>
        <strong>Is eInvoice:</strong> No<br>
        <strong>Operator code:</strong> {{ $invoice->cr }}<br>
        <strong>Software code:</strong> {{ $invoice->sw }}<br>
        <strong>Pay deadline:</strong> {{ $invoice->created_at->format('d/m/Y') }}<br>
        <strong>Status:</strong> {{ $invoice->fiscalization_status }}<br>
        @if($invoice->fiscalization_url)
            <strong>Verification URL:</strong> <a href="{{ $invoice->fiscalization_url }}" target="_blank">Verify Invoice</a><br>
        @endif
    </div>
    <h3>Invoice items list</h3>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Description</th>
                <th>Amount</th>
                <th>Total</th>
                <th>Quantity</th>
                <th>Base</th>
                <th>VAT</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td>{{ number_format($item->price, 2) }} LEK</td>
                <td>{{ number_format($item->total, 2) }} LEK</td>
                <td>{{ $item->quantity }}</td>
                <td>{{ number_format($item->total / 1.2, 2) }} LEK</td>
                <td>VAT: {{ number_format($item->total - ($item->total / 1.2), 2) }} LEK</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <h4>Same tax items</h4>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Number of items</th>
                <th>Rate %</th>
                <th>Base</th>
                <th>VAT</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $invoice->items->count() }}</td>
                <td>20</td>
                <td>{{ number_format($invoice->items->sum(function($i){return $i->total / 1.2;}), 2) }} LEK</td>
                <td>{{ number_format($invoice->items->sum(function($i){return $i->total - ($i->total / 1.2);}), 2) }} LEK</td>
            </tr>
        </tbody>
    </table>
    <h4>Payment methods</h4>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Type</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Banknotes and coins</td>
                <td>{{ number_format($invoice->total, 2) }} LEK</td>
            </tr>
        </tbody>
    </table>
    <h4>Buyer details</h4>
    <div class="mb-3">
        <strong>Name:</strong> {{ $invoice->client->name ?? 'N/A' }}<br>
        <strong>Address:</strong> {{ $invoice->client->address ?? 'N/A' }}<br>
        <strong>Buyer Tax Number:</strong> {{ $invoice->client->tax_number ?? '' }}<br>
    </div>
    <a href="{{ route('invoices.index') }}" class="btn btn-secondary">Back to Invoices</a>
    @if(Auth::check() && Auth::user()->isAdmin() && $invoice->created_by === Auth::id())
        <a href="{{ route('invoices.edit', $invoice) }}" class="btn btn-warning">Edit</a>
        <form action="{{ route('invoices.destroy', $invoice) }}" method="POST" style="display:inline-block">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger" onclick="return confirm('Delete this invoice?')">Delete</button>
        </form>
    @endif
</div>
@endsection 
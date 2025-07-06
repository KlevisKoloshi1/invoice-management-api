@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Edit Invoice #{{ $invoice->id }}</h1>
    <form method="POST" action="{{ route('invoices.update', $invoice) }}">
        @csrf
        @method('PUT')
        <div class="mb-3">
            <label for="client_id" class="form-label">Client</label>
            <select name="client_id" id="client_id" class="form-control" required>
                @foreach(\App\Models\Client::all() as $client)
                    <option value="{{ $client->id }}" {{ $invoice->client_id == $client->id ? 'selected' : '' }}>{{ $client->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label for="total" class="form-label">Total Amount</label>
            <input type="number" step="0.01" name="total" id="total" class="form-control" value="{{ $invoice->total }}" required>
        </div>
        <div class="mb-3">
            <label for="status" class="form-label">Status</label>
            <input type="text" name="status" id="status" class="form-control" value="{{ $invoice->status }}">
        </div>
        <button type="submit" class="btn btn-primary">Update Invoice</button>
        <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-secondary">Cancel</a>
    </form>
</div>
@endsection 
@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Create Invoice</h1>
    <form method="POST" action="{{ route('invoices.store') }}">
        @csrf
        <div class="mb-3">
            <label for="client_id" class="form-label">Client</label>
            <select name="client_id" id="client_id" class="form-control" required>
                @foreach(\App\Models\Client::all() as $client)
                    <option value="{{ $client->id }}">{{ $client->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label for="total" class="form-label">Total Amount</label>
            <input type="number" step="0.01" name="total" id="total" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary">Create Invoice</button>
    </form>
</div>
@endsection 
@extends('layouts.app')
@section('content')
<div class="container">
    <h1>Invoices</h1>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>ID</th>
                <th>Client</th>
                <th>Total</th>
                <th>Status</th>
                <th>Fiscalized</th>
                <th>Created At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoices as $invoice)
            <tr>
                <td>{{ $invoice->id }}</td>
                <td>{{ $invoice->client->name ?? 'N/A' }}</td>
                <td>{{ $invoice->total }}</td>
                <td>{{ $invoice->status }}</td>
                <td>{{ $invoice->fiscalized ? 'Yes' : 'No' }}</td>
                <td>{{ $invoice->created_at }}</td>
                <td>
                    <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-sm btn-info">Details</a>
                    @if(Auth::check() && $invoice->created_by === Auth::id())
                        <a href="{{ route('invoices.edit', $invoice) }}" class="btn btn-sm btn-warning">Edit</a>
                        <form action="{{ route('invoices.destroy', $invoice) }}" method="POST" style="display:inline-block">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this invoice?')">Delete</button>
                        </form>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    {{ $invoices->links() }}
</div>
@endsection 
@extends('layouts.app')
@section('content')
<div class="container">
    <h1>Imports</h1>
    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if(Auth::check() && Auth::user()->isAdmin())
        <a href="{{ route('imports.create') }}" class="btn btn-primary mb-3">Upload Import</a>
    @endif
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>ID</th>
                <th>File</th>
                <th>Status</th>
                <th>Created By</th>
                <th>Created At</th>
                @if(Auth::check() && Auth::user()->isAdmin())
                    <th>Actions</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($imports as $import)
            <tr>
                <td>{{ $import->id }}</td>
                <td>{{ $import->file_path }}</td>
                <td>{{ $import->status }}</td>
                <td>{{ $import->user->name ?? 'N/A' }}</td>
                <td>{{ $import->created_at }}</td>
                @if(Auth::check() && Auth::user()->isAdmin())
                <td>
                    <a href="{{ route('imports.edit', $import) }}" class="btn btn-sm btn-warning">Edit</a>
                    <form action="{{ route('imports.destroy', $import) }}" method="POST" style="display:inline-block">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this import?')">Delete</button>
                    </form>
                </td>
                @endif
            </tr>
            @endforeach
        </tbody>
    </table>
    {{ $imports->links() }}
</div>
@endsection 
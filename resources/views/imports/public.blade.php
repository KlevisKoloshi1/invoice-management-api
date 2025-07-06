@extends('layouts.app')
@section('content')
<div class="container">
    <h1>Public Imports</h1>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>ID</th>
                <th>File</th>
                <th>Status</th>
                <th>Created At</th>
            </tr>
        </thead>
        <tbody>
            @foreach($imports as $import)
            <tr>
                <td>{{ $import->id }}</td>
                <td>{{ $import->file_path }}</td>
                <td>{{ $import->status }}</td>
                <td>{{ $import->created_at }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    {{ $imports->links() }}
</div>
@endsection 
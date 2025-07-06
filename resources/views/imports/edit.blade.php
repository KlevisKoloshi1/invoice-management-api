@extends('layouts.app')
@section('content')
<div class="container">
    <h1>Edit Import #{{ $import->id }}</h1>
    @if($errors->any())
        <div class="alert alert-danger">
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <form action="{{ route('imports.update', $import) }}" method="POST" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        <div class="mb-3">
            <label for="status" class="form-label">Status</label>
            <input type="text" name="status" id="status" class="form-control" value="{{ $import->status }}">
        </div>
        <div class="mb-3">
            <label for="file" class="form-label">Replace File (optional)</label>
            <input type="file" name="file" id="file" class="form-control">
        </div>
        <button type="submit" class="btn btn-primary">Update</button>
        <a href="{{ route('imports.index') }}" class="btn btn-secondary">Back</a>
    </form>
</div>
@endsection 
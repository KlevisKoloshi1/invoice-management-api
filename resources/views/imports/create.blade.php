@extends('layouts.app')
@section('content')
<div class="container">
    <h1>Upload Import</h1>
    @if($errors->any())
        <div class="alert alert-danger">
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <form action="{{ route('imports.store') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="mb-3">
            <label for="file" class="form-label">Excel File</label>
            <input type="file" name="file" id="file" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-success">Upload</button>
        <a href="{{ route('imports.index') }}" class="btn btn-secondary">Back</a>
    </form>
</div>
@endsection

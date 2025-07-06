<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light bg-light mb-4">
    <div class="container">
        <a class="navbar-brand" href="/">Invoice Management</a>
        <div class="collapse navbar-collapse">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                @if(Auth::check() && Auth::user()->isAdmin())
                    <li class="nav-item"><a class="nav-link" href="{{ route('imports.index') }}">Imports</a></li>
                @endif
                <li class="nav-item"><a class="nav-link" href="{{ route('invoices.index') }}">Invoices</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ route('imports.public') }}">Public Imports</a></li>
            </ul>
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                @if(Auth::check())
                    <li class="nav-item"><span class="nav-link">{{ Auth::user()->name }}</span></li>
                    <li class="nav-item">
                        <form action="/logout" method="POST" style="display:inline;">
                            @csrf
                            <button class="btn btn-link nav-link" type="submit">Logout</button>
                        </form>
                    </li>
                @else
                    <li class="nav-item"><a class="nav-link" href="/login">Login</a></li>
                @endif
            </ul>
        </div>
    </div>
</nav>
<main>
    <div class="container">
        @if(Auth::check())
            <div class="mb-4 d-flex gap-2">
                <a href="{{ route('imports.create') }}" class="btn btn-success">Import Excel</a>
                <a href="{{ route('imports.index') }}" class="btn btn-outline-primary">View Uploads</a>
            </div>
        @endif
        @if($errors->has('auth'))
            <div class="alert alert-danger">{{ $errors->first('auth') }}</div>
        @endif
    </div>
    @yield('content')
</main>
</body>
</html> 
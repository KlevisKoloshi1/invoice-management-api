<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome | Invoice Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8fafc; }
        .welcome-card { max-width: 400px; margin: 80px auto; box-shadow: 0 2px 16px #0001; border-radius: 16px; }
        .welcome-logo { font-size: 2.5rem; font-weight: bold; color: #f53003; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card welcome-card p-4 text-center">
            <div class="welcome-logo mb-3">Invoice Management</div>
            @if(Auth::check())
                <div class="mb-3">Welcome, <strong>{{ Auth::user()->name }}</strong>!</div>
                <form action="/logout" method="POST" class="mb-2">
                    @csrf
                    <button class="btn btn-outline-danger w-100" type="submit">Logout</button>
                </form>
                @if(Auth::user()->isAdmin())
                    <a href="{{ route('imports.index') }}" class="btn btn-primary w-100 mb-2">Go to Import Management</a>
                @endif
                <a href="{{ route('invoices.index') }}" class="btn btn-secondary w-100">View Invoices</a>
            @else
                <a href="{{ route('invoices.index') }}" class="btn btn-primary w-100 mb-2">View Invoices</a>
                <a href="{{ route('login') }}" class="btn btn-outline-primary w-100 mb-2">Log in</a>
                <a href="{{ route('register') }}" class="btn btn-outline-secondary w-100">Register</a>
            @endif
        </div>
    </div>
</body>
</html>

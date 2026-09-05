<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Login — License Server</title>
    <style>
        :root {
            --bg-color: #0f172a;
            --card-bg: #1e293b;
            --border-color: #334155;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --danger-bg: rgba(239, 68, 68, 0.15);
            --danger-border: #ef4444;
            --danger-text: #fca5a5;
        }
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .login-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 0.75rem;
            width: 100%;
            max-width: 420px;
            padding: 2.25rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
        }
        .brand-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .brand-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -0.025em;
        }
        .brand-header p {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        .form-group label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--text-main);
        }
        .form-control {
            width: 100%;
            padding: 0.65rem 0.875rem;
            background-color: #0f172a;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            color: var(--text-main);
            font-size: 0.925rem;
            outline: none;
            transition: border-color 0.15s ease-in-out;
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 1px var(--primary);
        }
        .form-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .form-check input[type="checkbox"] {
            accent-color: var(--primary);
            width: 1rem;
            height: 1rem;
            cursor: pointer;
        }
        .form-check label {
            font-size: 0.875rem;
            color: var(--text-muted);
            cursor: pointer;
        }
        .btn-submit {
            width: 100%;
            padding: 0.75rem 1rem;
            background-color: var(--primary);
            color: #ffffff;
            font-weight: 600;
            font-size: 0.95rem;
            border: none;
            border-radius: 0.375rem;
            cursor: pointer;
            transition: background-color 0.15s ease-in-out;
        }
        .btn-submit:hover {
            background-color: var(--primary-hover);
        }
        .alert-danger {
            background-color: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: var(--danger-text);
            padding: 0.75rem 1rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="brand-header">
            <h1>Central License Server</h1>
            <p>Admin Authentication Portal</p>
        </div>

        @if ($errors->any())
            <div class="alert-danger" role="alert">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form action="{{ route('login.store') }}" method="POST">
            @csrf

            <div class="form-group">
                <label for="email">Admin Email</label>
                <input type="email" id="email" name="email" class="form-control" value="{{ old('email') }}" required autofocus autocomplete="username">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" required autocomplete="current-password">
            </div>

            <div class="form-check">
                <input type="checkbox" id="remember" name="remember" value="1">
                <label for="remember">Remember this session</label>
            </div>

            <button type="submit" class="btn-submit">Sign In to Dashboard</button>
        </form>
    </div>
</body>
</html>

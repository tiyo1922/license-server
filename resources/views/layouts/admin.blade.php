<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Admin Dashboard') — License Server</title>
    <style>
        :root {
            --bg-color: #0b1120;
            --surface-bg: #1e293b;
            --header-bg: #0f172a;
            --border-color: #334155;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --danger: #ef4444;
            --danger-hover: #dc2626;
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
            flex-direction: column;
        }
        header {
            background-color: var(--header-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 0.875rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .brand-logo {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
        }
        .nav-links {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            margin-left: 2.5rem;
        }
        .nav-link {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: color 0.15s ease-in-out;
        }
        .nav-link:hover, .nav-link.active {
            color: var(--text-main);
        }
        .header-left {
            display: flex;
            align-items: center;
        }
        .user-nav {
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }
        .user-badge {
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        .user-name {
            font-weight: 600;
            color: var(--text-main);
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease-in-out;
            border: 1px solid transparent;
        }
        .btn-primary {
            background-color: var(--primary);
            color: #ffffff;
        }
        .btn-primary:hover {
            background-color: var(--primary-hover);
        }
        .btn-secondary {
            background-color: transparent;
            border-color: var(--border-color);
            color: var(--text-main);
        }
        .btn-secondary:hover {
            background-color: var(--border-color);
        }
        .btn-logout {
            background-color: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            padding: 0.4rem 0.85rem;
            border-radius: 0.375rem;
            font-size: 0.825rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s ease-in-out;
        }
        .btn-logout:hover {
            background-color: var(--danger);
            border-color: var(--danger);
            color: #ffffff;
        }
        main {
            flex: 1;
            padding: 2rem;
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
        }
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.75rem;
        }
        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
        }
        .page-subtitle {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
        .card {
            background-color: var(--surface-bg);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            color: var(--text-main);
        }
        .card-body {
            font-size: 0.925rem;
            color: var(--text-muted);
            line-height: 1.5;
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        .form-label {
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
            box-sizing: border-box;
        }
        .form-control:focus {
            border-color: var(--primary);
        }
        .form-text {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.35rem;
        }
        .alert {
            padding: 1rem 1.25rem;
            border-radius: 0.375rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        .alert-success {
            background-color: rgba(34, 197, 94, 0.15);
            border: 1px solid #22c55e;
            color: #86efac;
        }
        .alert-danger {
            background-color: rgba(239, 68, 68, 0.15);
            border: 1px solid #ef4444;
            color: #fca5a5;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.55rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .badge-unused {
            background-color: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.4);
        }
        .badge-active {
            background-color: rgba(34, 197, 94, 0.2);
            color: #86efac;
            border: 1px solid rgba(34, 197, 94, 0.4);
        }
        .badge-suspended {
            background-color: rgba(234, 179, 8, 0.2);
            color: #fde047;
            border: 1px solid rgba(234, 179, 8, 0.4);
        }
        .badge-revoked {
            background-color: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.4);
        }
        .badge-expired {
            background-color: rgba(148, 163, 184, 0.2);
            color: #cbd5e1;
            border: 1px solid rgba(148, 163, 184, 0.4);
        }
        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            text-align: left;
        }
        th {
            background-color: #0f172a;
            color: var(--text-muted);
            font-weight: 600;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        td {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
        }
        tr:hover td {
            background-color: rgba(255, 255, 255, 0.02);
        }
        .code-key {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-weight: 600;
            letter-spacing: 0.05em;
            color: #38bdf8;
            background: #0f172a;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            border: 1px solid var(--border-color);
        }
    </style>
</head>
<body>
    <header>
        <div class="header-left">
            <a href="{{ route('admin.dashboard') }}" class="brand-logo">
                <span>🛡️ Central License Server</span>
            </a>
            <nav class="nav-links">
                <a href="{{ route('admin.dashboard') }}" class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">Dashboard</a>
                <a href="{{ route('admin.applications.index') }}" class="nav-link {{ request()->routeIs('admin.applications.*') ? 'active' : '' }}">Applications</a>
                <a href="{{ route('admin.licenses.index') }}" class="nav-link {{ request()->routeIs('admin.licenses.*') ? 'active' : '' }}">Licenses</a>
                <a href="{{ route('admin.audit-logs.index') }}" class="nav-link {{ request()->routeIs('admin.audit-logs.*') ? 'active' : '' }}">Audit Logs</a>
            </nav>
        </div>
        <div class="user-nav">
            <span class="user-badge">Logged in as <span class="user-name">{{ Auth::user()->name ?? 'Administrator' }}</span> ({{ Auth::user()->email ?? '' }})</span>
            <form action="{{ route('logout') }}" method="POST" style="display: inline;">
                @csrf
                <button type="submit" class="btn-logout">Logout</button>
            </form>
        </div>
    </header>

    <main>
        @if (session('success') || isset($success))
            <div class="alert alert-success">
                {{ session('success') ?? $success }}
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-danger">
                {{ session('error') }}
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>

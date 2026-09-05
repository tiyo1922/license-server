@extends('layouts.admin')

@section('title', 'Application Management')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Application Management</h1>
        <p class="page-subtitle">Register and configure client applications, issue API credentials, and manage application lifecycle</p>
    </div>
    <div>
        <a href="{{ route('admin.applications.create') }}" class="btn btn-primary">+ Register New Application</a>
    </div>
</div>

<div class="card" style="margin-bottom: 1.5rem; padding: 1.25rem;">
    <form method="GET" action="{{ route('admin.applications.index') }}" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
        <div style="flex: 1; min-width: 220px;">
            <label for="search" class="form-label" style="font-size: 0.8rem; margin-bottom: 0.35rem;">Search (Name, Code, Description)</label>
            <input type="text" id="search" name="search" class="form-control" value="{{ request('search') }}" placeholder="Filter by keyword...">
        </div>
        <div style="width: 160px;">
            <label for="status" class="form-label" style="font-size: 0.8rem; margin-bottom: 0.35rem;">Status</label>
            <select id="status" name="status" class="form-control">
                <option value="">All Statuses</option>
                <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
            </select>
        </div>
        <div>
            <button type="submit" class="btn btn-secondary">Filter</button>
            <a href="{{ route('admin.applications.index') }}" class="btn btn-secondary" style="margin-left: 0.25rem;">Reset</a>
        </div>
    </form>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Application Name</th>
                    <th>Code (Identifier)</th>
                    <th>Status</th>
                    <th>Active API Keys</th>
                    <th>Issued Licenses</th>
                    <th>Registered At</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($applications as $app)
                    <tr>
                        <td>
                            <strong style="color: var(--text-main); font-size: 0.95rem;">{{ $app->name }}</strong>
                            @if ($app->description)
                                <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.2rem;">{{ Str::limit($app->description, 60) }}</div>
                            @endif
                        </td>
                        <td>
                            <span class="code-key" style="font-size: 0.85rem;">{{ $app->code }}</span>
                        </td>
                        <td>
                            @if ($app->is_active)
                                <span class="badge badge-active" style="font-size: 0.75rem;">ACTIVE</span>
                            @else
                                <span class="badge badge-suspended" style="font-size: 0.75rem;">INACTIVE</span>
                            @endif
                        </td>
                        <td>
                            <span style="font-weight: 600; color: #38bdf8;">{{ $app->active_api_keys_count ?? $app->apiKeys()->where('status', 'ACTIVE')->count() }}</span>
                            <span style="font-size: 0.75rem; color: var(--text-muted);">/ {{ $app->api_keys_count ?? $app->apiKeys()->count() }} total</span>
                        </td>
                        <td>
                            <span style="font-weight: 600;">{{ $app->licenses_count ?? $app->licenses()->count() }}</span>
                        </td>
                        <td>
                            <span style="font-size: 0.85rem; color: var(--text-muted);">{{ $app->created_at->format('Y-m-d H:i') }} UTC</span>
                        </td>
                        <td style="text-align: right; white-space: nowrap;">
                            <a href="{{ route('admin.applications.show', $app) }}" class="btn btn-secondary" style="padding: 0.3rem 0.65rem; font-size: 0.8rem; margin-right: 0.25rem;">View & Keys</a>
                            <a href="{{ route('admin.applications.edit', $app) }}" class="btn btn-secondary" style="padding: 0.3rem 0.65rem; font-size: 0.8rem;">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 3rem 1rem;">
                            No applications found matching the criteria.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($applications->hasPages())
        <div style="padding: 1.25rem; border-top: 1px solid var(--border-color);">
            {{ $applications->links() }}
        </div>
    @endif
</div>
@endsection

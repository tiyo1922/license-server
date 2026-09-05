@extends('layouts.admin')

@section('title', 'License Management')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">License Management</h1>
        <p class="page-subtitle">Generate, inspect, and manage application client licenses</p>
    </div>
    <div>
        <a href="{{ route('admin.licenses.create') }}" class="btn btn-primary">+ Generate New License</a>
    </div>
</div>

<div class="card" style="margin-bottom: 1.5rem; padding: 1.25rem;">
    <form method="GET" action="{{ route('admin.licenses.index') }}" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
        <div style="flex: 1; min-width: 200px;">
            <label for="search" class="form-label" style="font-size: 0.8rem; margin-bottom: 0.35rem;">Search (Key, Customer, Email)</label>
            <input type="text" id="search" name="search" class="form-control" value="{{ request('search') }}" placeholder="Filter by keyword...">
        </div>
        <div style="width: 200px;">
            <label for="application_id" class="form-label" style="font-size: 0.8rem; margin-bottom: 0.35rem;">Application</label>
            <select id="application_id" name="application_id" class="form-control">
                <option value="">All Applications</option>
                @foreach ($applications as $app)
                    <option value="{{ $app->id }}" {{ request('application_id') == $app->id ? 'selected' : '' }}>
                        {{ $app->name }} ({{ $app->code }})
                    </option>
                @endforeach
            </select>
        </div>
        <div style="width: 160px;">
            <label for="status" class="form-label" style="font-size: 0.8rem; margin-bottom: 0.35rem;">Status</label>
            <select id="status" name="status" class="form-control">
                <option value="">All Statuses</option>
                <option value="UNUSED" {{ request('status') === 'UNUSED' ? 'selected' : '' }}>UNUSED</option>
                <option value="ACTIVE" {{ request('status') === 'ACTIVE' ? 'selected' : '' }}>ACTIVE</option>
                <option value="SUSPENDED" {{ request('status') === 'SUSPENDED' ? 'selected' : '' }}>SUSPENDED</option>
                <option value="REVOKED" {{ request('status') === 'REVOKED' ? 'selected' : '' }}>REVOKED</option>
                <option value="EXPIRED" {{ request('status') === 'EXPIRED' ? 'selected' : '' }}>EXPIRED</option>
            </select>
        </div>
        <div>
            <button type="submit" class="btn btn-secondary">Filter</button>
            <a href="{{ route('admin.licenses.index') }}" class="btn btn-secondary" style="margin-left: 0.25rem;">Reset</a>
        </div>
    </form>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Masked License Key</th>
                    <th>Application</th>
                    <th>Customer</th>
                    <th>Status</th>
                    <th>Validity / Expiration</th>
                    <th>Created At</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($licenses as $license)
                    <tr>
                        <td>
                            <span class="code-key">{{ $license->key_masked }}</span>
                        </td>
                        <td>
                            <strong>{{ $license->application->name ?? 'N/A' }}</strong>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">{{ $license->application->code ?? '' }}</div>
                        </td>
                        <td>
                            @if ($license->customer_name || $license->customer_email)
                                <div>{{ $license->customer_name ?? '—' }}</div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">{{ $license->customer_email ?? '' }}</div>
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td>
                            @php
                                $statusClass = match($license->status->value ?? $license->status) {
                                    'UNUSED' => 'badge-unused',
                                    'ACTIVE' => 'badge-active',
                                    'SUSPENDED' => 'badge-suspended',
                                    'REVOKED' => 'badge-revoked',
                                    'EXPIRED' => 'badge-expired',
                                    default => 'badge-unused',
                                };
                            @endphp
                            <span class="badge {{ $statusClass }}">
                                {{ $license->status->value ?? $license->status }}
                            </span>
                        </td>
                        <td>
                            @if ($license->expires_at)
                                <span>{{ $license->expires_at->format('Y-m-d H:i') }} UTC</span>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">{{ $license->expires_at->diffForHumans() }}</div>
                            @else
                                <span style="color: #86efac; font-weight: 500;">Lifetime</span>
                            @endif
                        </td>
                        <td>
                            <span>{{ $license->created_at->format('Y-m-d H:i') }}</span>
                        </td>
                        <td style="text-align: right;">
                            <a href="{{ route('admin.licenses.show', $license) }}" class="btn btn-secondary" style="padding: 0.3rem 0.65rem; font-size: 0.8rem;">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                            <div style="font-size: 2rem; margin-bottom: 0.5rem;">🔑</div>
                            <p style="font-size: 1rem; font-weight: 500; color: var(--text-main);">No licenses found</p>
                            <p style="margin-top: 0.25rem;">Generate your first license key to get started.</p>
                            <div style="margin-top: 1rem;">
                                <a href="{{ route('admin.licenses.create') }}" class="btn btn-primary">+ Generate New License</a>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($licenses->hasPages())
        <div style="padding: 1rem 1.5rem; border-top: 1px solid var(--border-color);">
            {{ $licenses->links() }}
        </div>
    @endif
</div>
@endsection

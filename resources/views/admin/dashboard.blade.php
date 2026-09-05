@extends('layouts.admin')

@section('title', 'System Dashboard')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Operational Dashboard</h1>
        <p class="page-subtitle">Centralized License Authority Status & Metrics for <span class="code-key">license.katresnanku.com</span></p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="{{ route('admin.applications.create') }}" class="btn btn-secondary">+ New Application</a>
        <a href="{{ route('admin.licenses.create') }}" class="btn btn-primary">+ Issue License</a>
    </div>
</div>

{{-- Top Metric Summary Cards --}}
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-bottom: 1.75rem;">
    <div class="card" style="margin-bottom: 0; padding: 1.25rem;">
        <div style="font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 0.5rem;">
            Applications
        </div>
        <div style="font-size: 2rem; font-weight: 700; color: #38bdf8; line-height: 1;">
            {{ $totalApplications }}
        </div>
        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.5rem;">
            <strong style="color: #86efac;">{{ $activeApplications }}</strong> active / {{ $totalApplications - $activeApplications }} inactive
        </div>
    </div>

    <div class="card" style="margin-bottom: 0; padding: 1.25rem;">
        <div style="font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 0.5rem;">
            Active API Keys
        </div>
        <div style="font-size: 2rem; font-weight: 700; color: #818cf8; line-height: 1;">
            {{ $activeApiKeys }}
        </div>
        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.5rem;">
            Authorized machine credentials
        </div>
    </div>

    <div class="card" style="margin-bottom: 0; padding: 1.25rem;">
        <div style="font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 0.5rem;">
            Total Issued Licenses
        </div>
        <div style="font-size: 2rem; font-weight: 700; color: #f8fafc; line-height: 1;">
            {{ $totalLicenses }}
        </div>
        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.5rem;">
            <strong style="color: #86efac;">{{ $activeLicenses }}</strong> active licenses
        </div>
    </div>

    <div class="card" style="margin-bottom: 0; padding: 1.25rem;">
        <div style="font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 0.5rem;">
            Domain Activations
        </div>
        <div style="font-size: 2rem; font-weight: 700; color: #34d399; line-height: 1;">
            {{ $totalActivations }}
        </div>
        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.5rem;">
            Bound client installations
        </div>
    </div>
</div>

{{-- License Status Breakdown & System Info --}}
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-bottom: 1.75rem;">
    <div class="card" style="margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h2 class="card-title" style="margin-bottom: 0;">License Lifecycle Breakdown</h2>
            <a href="{{ route('admin.licenses.index') }}" class="btn btn-secondary" style="font-size: 0.75rem; padding: 0.25rem 0.55rem;">View All Licenses →</a>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 1rem; margin-top: 1rem;">
            <div style="background: #0f172a; padding: 1rem; border-radius: 0.5rem; border: 1px solid var(--border-color); text-align: center;">
                <div class="badge badge-active" style="margin-bottom: 0.5rem;">ACTIVE</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #86efac;">{{ $activeLicenses }}</div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">{{ $totalLicenses > 0 ? round(($activeLicenses / $totalLicenses) * 100) : 0 }}% of total</div>
            </div>
            <div style="background: #0f172a; padding: 1rem; border-radius: 0.5rem; border: 1px solid var(--border-color); text-align: center;">
                <div class="badge badge-suspended" style="margin-bottom: 0.5rem;">SUSPENDED</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #fde047;">{{ $suspendedLicenses }}</div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Temporary lock</div>
            </div>
            <div style="background: #0f172a; padding: 1rem; border-radius: 0.5rem; border: 1px solid var(--border-color); text-align: center;">
                <div class="badge badge-expired" style="margin-bottom: 0.5rem;">EXPIRED</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #94a3b8;">{{ $expiredLicenses }}</div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Term expired</div>
            </div>
            <div style="background: #0f172a; padding: 1rem; border-radius: 0.5rem; border: 1px solid var(--border-color); text-align: center;">
                <div class="badge badge-revoked" style="margin-bottom: 0.5rem;">REVOKED</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #f87171;">{{ $revokedLicenses }}</div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Permanently killed</div>
            </div>
            <div style="background: #0f172a; padding: 1rem; border-radius: 0.5rem; border: 1px solid var(--border-color); text-align: center;">
                <div class="badge badge-unused" style="margin-bottom: 0.5rem;">UNUSED</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #cbd5e1;">{{ $unusedLicenses }}</div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Pending activation</div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 0;">
        <h2 class="card-title">Cryptographic Node Info</h2>
        <div class="card-body" style="font-size: 0.85rem; display: flex; flex-direction: column; gap: 0.75rem;">
            <div>
                <span style="color: var(--text-muted);">Authority Domain:</span>
                <div style="font-weight: 600; color: #38bdf8;">license.katresnanku.com</div>
            </div>
            <div>
                <span style="color: var(--text-muted);">Algorithm & Key Engine:</span>
                <div style="font-weight: 600; color: #a78bfa;">Ed25519 (Pure Edwards Curve)</div>
            </div>
            <div>
                <span style="color: var(--text-muted);">Authoritative Clock:</span>
                <div style="font-weight: 600;">UTC ({{ now('UTC')->format('Y-m-d H:i') }} UTC)</div>
            </div>
            <div>
                <span style="color: var(--text-muted);">System Health API:</span>
                <div><a href="{{ route('api.health') }}" target="_blank" style="color: #34d399; text-decoration: none;">GET /api/health ↗</a></div>
            </div>
        </div>
    </div>
</div>

{{-- Recent Activity Feed --}}
<div class="card" style="padding: 0; overflow: hidden;">
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 class="card-title" style="margin-bottom: 0.25rem;">Recent Security & Audit Activity</h2>
            <p style="font-size: 0.825rem; color: var(--text-muted);">Latest administrative, activation, and lifecycle events recorded in the authority log</p>
        </div>
        <div>
            <a href="{{ route('admin.audit-logs.index') }}" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;">View Full Audit Trail →</a>
        </div>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Timestamp (UTC)</th>
                    <th>Event</th>
                    <th>Actor</th>
                    <th>Target Application / License</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recentLogs as $log)
                    <tr>
                        <td style="font-size: 0.85rem; color: var(--text-muted); white-space: nowrap;">
                            {{ $log->created_at->format('Y-m-d H:i:s') }}
                        </td>
                        <td>
                            <span class="code-key" style="font-size: 0.75rem; color: #e2e8f0; background: #1e293b;">
                                {{ $log->event->value }}
                            </span>
                        </td>
                        <td>
                            <div style="font-size: 0.85rem;">
                                @if ($log->actor_type->value === 'ADMIN')
                                    <span style="color: #38bdf8; font-weight: 500;">👤 Admin ({{ $log->actorUser?->email ?: 'ID: ' . $log->actor_user_id }})</span>
                                @elseif ($log->actor_type->value === 'APPLICATION_KEY')
                                    <span style="color: #a78bfa; font-weight: 500;">🔑 API Key ({{ $log->actor_key_id }})</span>
                                @elseif ($log->actor_type->value === 'CLIENT')
                                    <span style="color: #34d399; font-weight: 500;">🌐 Client</span>
                                @else
                                    <span style="color: var(--text-muted);">⚙️ System</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            <div style="font-size: 0.85rem;">
                                @if ($log->application)
                                    <a href="{{ route('admin.applications.show', $log->application) }}" style="color: #38bdf8; text-decoration: none; font-weight: 600;">
                                        {{ $log->application->code }}
                                    </a>
                                @endif
                                @if ($log->license)
                                    <span style="color: var(--text-muted);"> / </span>
                                    <a href="{{ route('admin.licenses.show', $log->license) }}" class="code-key" style="text-decoration: none; font-size: 0.75rem;">
                                        {{ $log->license->key_masked }}
                                    </a>
                                @endif
                                @if (! $log->application && ! $log->license)
                                    <span style="color: var(--text-muted);">—</span>
                                @endif
                            </div>
                        </td>
                        <td style="font-size: 0.85rem; color: var(--text-muted);">
                            {{ $log->ip_address ?: '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2.5rem 1rem;">
                            No audit activity recorded yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

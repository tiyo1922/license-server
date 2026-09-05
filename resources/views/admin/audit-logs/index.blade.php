@extends('layouts.admin')

@section('title', 'Audit Logs')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Security & Audit Logs</h1>
        <p class="page-subtitle">Immutable audit trail of all administrative actions, lifecycle transitions, and API authentications</p>
    </div>
</div>

{{-- Filters Form --}}
<div class="card" style="margin-bottom: 1.5rem; padding: 1.25rem;">
    <form method="GET" action="{{ route('admin.audit-logs.index') }}" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
        <div style="flex: 1; min-width: 180px;">
            <label for="search" class="form-label" style="font-size: 0.8rem; margin-bottom: 0.35rem;">Search (IP, User Agent, Key ID)</label>
            <input type="text" id="search" name="search" class="form-control" value="{{ request('search') }}" placeholder="Filter by keyword...">
        </div>

        <div style="width: 200px;">
            <label for="event" class="form-label" style="font-size: 0.8rem; margin-bottom: 0.35rem;">Event Type</label>
            <select id="event" name="event" class="form-control">
                <option value="">All Events</option>
                @foreach ($events as $eventCase)
                    <option value="{{ $eventCase->value }}" {{ request('event') === $eventCase->value ? 'selected' : '' }}>
                        {{ $eventCase->value }}
                    </option>
                @endforeach
            </select>
        </div>

        <div style="width: 180px;">
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
            <label for="actor_type" class="form-label" style="font-size: 0.8rem; margin-bottom: 0.35rem;">Actor Type</label>
            <select id="actor_type" name="actor_type" class="form-control">
                <option value="">All Actors</option>
                @foreach ($actorTypes as $type)
                    <option value="{{ $type->value }}" {{ request('actor_type') === $type->value ? 'selected' : '' }}>
                        {{ $type->value }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <button type="submit" class="btn btn-secondary">Filter</button>
            <a href="{{ route('admin.audit-logs.index') }}" class="btn btn-secondary" style="margin-left: 0.25rem;">Reset</a>
        </div>
    </form>
</div>

{{-- Audit Logs Table --}}
<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Timestamp (UTC)</th>
                    <th>Event</th>
                    <th>Actor</th>
                    <th>Application</th>
                    <th>License</th>
                    <th>IP / Client</th>
                    <th>Payload Details</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td style="font-size: 0.85rem; color: var(--text-muted); white-space: nowrap; vertical-align: top;">
                            {{ $log->created_at->format('Y-m-d H:i:s') }}
                        </td>
                        <td style="vertical-align: top;">
                            <span class="code-key" style="font-size: 0.75rem; color: #e2e8f0; background: #1e293b;">
                                {{ $log->event->value }}
                            </span>
                        </td>
                        <td style="font-size: 0.85rem; vertical-align: top;">
                            @if ($log->actor_type->value === 'ADMIN')
                                <div style="color: #38bdf8; font-weight: 500;">👤 Admin</div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">{{ $log->actorUser?->email ?: 'ID: ' . $log->actor_user_id }}</div>
                            @elseif ($log->actor_type->value === 'APPLICATION_KEY')
                                <div style="color: #a78bfa; font-weight: 500;">🔑 API Key</div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);"><code style="font-size: 0.75rem;">{{ $log->actor_key_id }}</code></div>
                            @elseif ($log->actor_type->value === 'CLIENT')
                                <div style="color: #34d399; font-weight: 500;">🌐 Client</div>
                            @else
                                <div style="color: var(--text-muted);">⚙️ System</div>
                            @endif
                        </td>
                        <td style="font-size: 0.85rem; vertical-align: top;">
                            @if ($log->application)
                                <a href="{{ route('admin.applications.show', $log->application) }}" style="color: #38bdf8; text-decoration: none; font-weight: 600;">
                                    {{ $log->application->code }}
                                </a>
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td style="font-size: 0.85rem; vertical-align: top;">
                            @if ($log->license)
                                <a href="{{ route('admin.licenses.show', $log->license) }}" class="code-key" style="text-decoration: none; font-size: 0.75rem;">
                                    {{ $log->license->key_masked }}
                                </a>
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td style="font-size: 0.8rem; color: var(--text-muted); vertical-align: top; max-width: 160px; word-break: break-all;">
                            <div>{{ $log->ip_address ?: '—' }}</div>
                            @if ($log->user_agent)
                                <div style="font-size: 0.7rem; color: #64748b; margin-top: 0.2rem;" title="{{ $log->user_agent }}">
                                    {{ Str::limit($log->user_agent, 30) }}
                                </div>
                            @endif
                        </td>
                        <td style="vertical-align: top;">
                            @if ($log->payload && count($log->payload) > 0)
                                <details style="cursor: pointer;">
                                    <summary style="font-size: 0.75rem; color: #38bdf8; user-select: none;">Inspect JSON</summary>
                                    <pre style="margin-top: 0.5rem; padding: 0.5rem; background: #0b1120; border: 1px solid var(--border-color); border-radius: 0.25rem; font-size: 0.75rem; color: #a5f3fc; overflow-x: auto; max-width: 320px; white-space: pre-wrap; word-break: break-all;">{{ json_encode($log->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            @else
                                <span style="color: var(--text-muted); font-size: 0.75rem;">Empty payload</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 3rem 1rem;">
                            No audit logs found matching criteria.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($logs->hasPages())
        <div style="padding: 1.25rem; border-top: 1px solid var(--border-color);">
            {{ $logs->links() }}
        </div>
    @endif
</div>
@endsection

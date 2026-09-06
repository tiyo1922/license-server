@extends('layouts.admin')

@section('title', 'License Details — ' . $license->key_masked)

@section('content')
@php
    $authStatus = $license->getAuthoritativeStatus();
    $statusValue = $authStatus->value ?? $authStatus;
    $statusClass = match($statusValue) {
        'UNUSED' => 'badge-unused',
        'ACTIVE' => 'badge-active',
        'SUSPENDED' => 'badge-suspended',
        'REVOKED' => 'badge-revoked',
        'EXPIRED' => 'badge-expired',
        default => 'badge-unused',
    };
@endphp

<div class="page-header">
    <div>
        <h1 class="page-title">License Details</h1>
        <p class="page-subtitle">Inspect configuration, audit trail, and validity for <span class="code-key">{{ $license->key_masked }}</span></p>
    </div>
    <div style="display: flex; gap: 0.5rem;">
        <a href="{{ route('admin.licenses.index') }}" class="btn btn-secondary">← Back to Licenses</a>
        <a href="{{ route('admin.licenses.create') }}" class="btn btn-primary">+ Generate Another</a>
    </div>
</div>

@if (isset($singleRevealKey) && $singleRevealKey)
    <div class="card" style="background-color: rgba(34, 197, 94, 0.1); border: 1px solid #22c55e; margin-bottom: 2rem;">
        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem;">
            <div>
                <h2 style="font-size: 1.15rem; font-weight: 700; color: #86efac; margin-bottom: 0.5rem;">
                    🛡️ Newly Generated Plaintext License Key
                </h2>
                <p style="font-size: 0.875rem; color: #bbf7d0; margin-bottom: 1rem;">
                    <strong>Important:</strong> Copy this license key now. For database security, the plaintext key exists purely as transient data and will <strong>NEVER</strong> be displayed or recoverable after leaving or reloading this page.
                </p>
                <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                    <span id="plaintext-key-box" class="code-key" style="font-size: 1.2rem; padding: 0.5rem 1rem; color: #ffffff; background: #0b1120; border-color: #22c55e;">
                        {{ $singleRevealKey }}
                    </span>
                    <button type="button" onclick="copyLicenseKey()" class="btn btn-primary" id="copy-btn" style="background-color: #22c55e; color: #0f172a; font-weight: 600;">
                        📋 Copy License Key
                    </button>
                    <span id="copy-feedback" style="font-size: 0.85rem; color: #86efac; display: none;">Copied to clipboard!</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        function copyLicenseKey() {
            const keyText = document.getElementById('plaintext-key-box').innerText.trim();
            navigator.clipboard.writeText(keyText).then(() => {
                const feedback = document.getElementById('copy-feedback');
                const btn = document.getElementById('copy-btn');
                feedback.style.display = 'inline';
                btn.innerText = '✅ Copied!';
                setTimeout(() => {
                    feedback.style.display = 'none';
                    btn.innerText = '📋 Copy License Key';
                }, 3000);
            });
        }
    </script>
@endif

{{-- Lifecycle Actions Control Bar --}}
<div class="card" style="margin-bottom: 1.5rem; background: #111827; border-color: #374151;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <span style="font-size: 0.875rem; color: var(--text-muted); margin-right: 0.5rem;">Authoritative Status:</span>
            <span class="badge {{ $statusClass }}" style="font-size: 0.85rem; padding: 0.25rem 0.65rem;">
                {{ $statusValue }}
            </span>
            @if ($license->isAuthoritativelyExpired() && $license->status->value !== 'EXPIRED' && $license->status->value !== 'REVOKED')
                <span style="font-size: 0.75rem; color: #f87171; margin-left: 0.5rem;">(Expired by server UTC time)</span>
            @endif
        </div>

        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
            @if ($statusValue === 'ACTIVE')
                {{-- Suspend Action --}}
                <form action="{{ route('admin.licenses.suspend', $license) }}" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to suspend this license? Client verification will be temporarily blocked.');">
                    @csrf
                    <button type="submit" class="btn btn-secondary" style="border-color: #eab308; color: #fde047;">⏸️ Suspend</button>
                </form>

                {{-- Renew Button (triggers modal/toggle) --}}
                <button type="button" onclick="toggleRenewForm()" class="btn btn-secondary" style="border-color: #3b82f6; color: #93c5fd;">🔄 Renew</button>

                {{-- Revoke Action --}}
                <form action="{{ route('admin.licenses.revoke', $license) }}" method="POST" style="display: inline;" onsubmit="return confirm('⚠️ CAUTION: Revocation is PERMANENT and terminal. This license can NEVER be reactivated or renewed. Proceed?');">
                    @csrf
                    <button type="submit" class="btn btn-secondary" style="border-color: #ef4444; color: #fca5a5;">🚫 Revoke</button>
                </form>

            @elseif ($statusValue === 'SUSPENDED')
                {{-- Unsuspend Action --}}
                <form action="{{ route('admin.licenses.unsuspend', $license) }}" method="POST" style="display: inline;" onsubmit="return confirm('Unsuspend this license and restore active client operations?');">
                    @csrf
                    <button type="submit" class="btn btn-primary" style="background-color: #22c55e; color: #0f172a; font-weight: 600;">▶️ Unsuspend</button>
                </form>

                {{-- Reset Binding Action --}}
                <form action="{{ route('admin.licenses.reset-binding', $license) }}" method="POST" style="display: inline;" onsubmit="return confirm('Reset activation binding? This will detach the current domain and return the license to UNUSED status for re-activation.');">
                    @csrf
                    <button type="submit" class="btn btn-secondary" style="border-color: #8b5cf6; color: #c4b5fd;">🔄 Reset Binding</button>
                </form>

                {{-- Renew Button --}}
                <button type="button" onclick="toggleRenewForm()" class="btn btn-secondary" style="border-color: #3b82f6; color: #93c5fd;">🔄 Renew</button>

                {{-- Revoke Action --}}
                <form action="{{ route('admin.licenses.revoke', $license) }}" method="POST" style="display: inline;" onsubmit="return confirm('⚠️ CAUTION: Revocation is PERMANENT. Proceed?');">
                    @csrf
                    <button type="submit" class="btn btn-secondary" style="border-color: #ef4444; color: #fca5a5;">🚫 Revoke</button>
                </form>

            @elseif ($statusValue === 'EXPIRED')
                {{-- Renew Action (Primary) --}}
                <button type="button" onclick="toggleRenewForm()" class="btn btn-primary">🔄 Renew License</button>

                @if ($license->getOriginStatusBeforeExpiration()->value === 'SUSPENDED')
                    {{-- Reset Binding if expired while suspended --}}
                    <form action="{{ route('admin.licenses.reset-binding', $license) }}" method="POST" style="display: inline;" onsubmit="return confirm('Reset activation binding and return to UNUSED?');">
                        @csrf
                        <button type="submit" class="btn btn-secondary" style="border-color: #8b5cf6; color: #c4b5fd;">🔄 Reset Binding</button>
                    </form>
                @endif

                {{-- Revoke Action --}}
                <form action="{{ route('admin.licenses.revoke', $license) }}" method="POST" style="display: inline;" onsubmit="return confirm('Permanently revoke this expired license?');">
                    @csrf
                    <button type="submit" class="btn btn-secondary" style="border-color: #ef4444; color: #fca5a5;">🚫 Revoke</button>
                </form>

            @elseif ($statusValue === 'UNUSED')
                <span style="font-size: 0.85rem; color: var(--text-muted);">Awaiting client activation in Phase 7.</span>
                <button type="button" onclick="toggleRenewForm()" class="btn btn-secondary" style="border-color: #3b82f6; color: #93c5fd; padding: 0.35rem 0.75rem; font-size: 0.8rem;">Extend Validity</button>

            @elseif ($statusValue === 'REVOKED')
                <span style="font-size: 0.85rem; color: #fca5a5;">🔒 Terminal state. No lifecycle actions permitted.</span>
            @endif
        </div>
    </div>

    {{-- Inline Renewal / Validity Extension Form --}}
    <div id="renew-form-container" style="display: none; margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid var(--border-color);">
        <form action="{{ route('admin.licenses.renew', $license) }}" method="POST" style="max-width: 540px;">
            @csrf
            <h3 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 0.75rem; color: #93c5fd;">Update License Validity / Renewal</h3>
            
            <div class="form-group">
                <label class="form-label" style="font-size: 0.8rem;">Validity / Expiration Strategy <span style="color: var(--danger);">*</span></label>
                <div style="display: flex; gap: 1.5rem; margin-top: 0.35rem; margin-bottom: 0.5rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; color: var(--text-main); font-size: 0.85rem;">
                        <input type="radio" name="validity_type" value="lifetime" {{ old('validity_type', $license->isLifetime() ? 'lifetime' : 'custom') === 'lifetime' ? 'checked' : '' }} onchange="toggleRenewExpirationInput()">
                        <span><strong>Lifetime</strong> (No expiration)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; color: var(--text-main); font-size: 0.85rem;">
                        <input type="radio" name="validity_type" value="custom" {{ old('validity_type', $license->isLifetime() ? 'lifetime' : 'custom') === 'custom' ? 'checked' : '' }} onchange="toggleRenewExpirationInput()">
                        <span><strong>Fixed Expiration Date</strong></span>
                    </label>
                </div>

                <div id="renew-expiration-input-container" style="display: {{ old('validity_type', $license->isLifetime() ? 'lifetime' : 'custom') === 'custom' ? 'block' : 'none' }}; margin-top: 0.75rem;">
                    <label for="renew_expires_at" class="form-label" style="font-size: 0.8rem;">New Expiration Date & Time (UTC) <span style="color: var(--danger);">*</span></label>
                    <input type="datetime-local" id="renew_expires_at" name="expires_at" class="form-control" style="max-width: 320px;" value="{{ old('expires_at', ($license->expires_at && $license->expires_at->isFuture()) ? $license->expires_at->format('Y-m-d\TH:i') : now('UTC')->addYear()->format('Y-m-d\TH:i')) }}">
                    <p class="form-text">Must be in the future relative to authoritative server UTC time.</p>
                </div>
            </div>

            <div class="form-group">
                <label for="renew_reason" class="form-label" style="font-size: 0.8rem;">Renewal Notes / Reference (Optional)</label>
                <input type="text" id="renew_reason" name="reason" class="form-control" placeholder="e.g. Annual renewal invoice #INV-2027-01 or Lifetime conversion" maxlength="500" value="{{ old('reason') }}">
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <button type="submit" class="btn btn-primary">Confirm Validity Update</button>
                <button type="button" onclick="toggleRenewForm()" class="btn btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleRenewForm() {
        const el = document.getElementById('renew-form-container');
        el.style.display = (el.style.display === 'none' || el.style.display === '') ? 'block' : 'none';
    }

    function toggleRenewExpirationInput() {
        const isCustom = document.querySelector('input[name="validity_type"]:checked').value === 'custom';
        const container = document.getElementById('renew-expiration-input-container');
        container.style.display = isCustom ? 'block' : 'none';
    }
</script>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
    <div class="card">
        <h2 class="card-title">License Information</h2>
        <div class="card-body">
            <div style="display: grid; grid-template-columns: 140px 1fr; row-gap: 0.85rem; font-size: 0.9rem;">
                <div style="color: var(--text-muted);">Masked Serial:</div>
                <div><span class="code-key">{{ $license->key_masked }}</span></div>

                <div style="color: var(--text-muted);">Status:</div>
                <div>
                    <span class="badge {{ $statusClass }}">
                        {{ $statusValue }}
                    </span>
                </div>

                <div style="color: var(--text-muted);">Application:</div>
                <div>
                    <strong>{{ $license->application->name ?? 'N/A' }}</strong>
                    <span style="color: var(--text-muted);">({{ $license->application->code ?? '' }})</span>
                </div>

                <div style="color: var(--text-muted);">Validity:</div>
                <div>
                    @if ($license->expires_at)
                        <span style="color: var(--text-main); font-weight: 500;">{{ $license->expires_at->format('Y-m-d H:i:s') }} UTC</span>
                        <div style="font-size: 0.8rem; color: var(--text-muted);">({{ $license->expires_at->diffForHumans() }})</div>
                    @else
                        <span style="color: #86efac; font-weight: 600;">Lifetime (No Expiration)</span>
                    @endif
                </div>

                <div style="color: var(--text-muted);">Created:</div>
                <div>{{ $license->created_at->format('Y-m-d H:i:s') }} UTC</div>

                <div style="color: var(--text-muted);">Updated:</div>
                <div>{{ $license->updated_at ? $license->updated_at->format('Y-m-d H:i:s') . ' UTC' : '—' }}</div>
            </div>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">Domain Binding & Customer</h2>
        <div class="card-body">
            <div style="display: grid; grid-template-columns: 140px 1fr; row-gap: 0.85rem; font-size: 0.9rem;">
                <div style="color: var(--text-muted);">Active Binding:</div>
                <div>
                    @if ($license->activation)
                        <strong style="color: #38bdf8;">{{ $license->activation->canonical_domain }}</strong>
                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.2rem;">
                            IP: <code>{{ $license->activation->ip_address ?? '—' }}</code> |
                            Activated: {{ $license->activation->activated_at ? $license->activation->activated_at->format('Y-m-d H:i') : '—' }}
                        </div>
                    @else
                        <span style="color: var(--text-muted);">No active binding (Unbound)</span>
                    @endif
                </div>

                <div style="color: var(--text-muted);">Customer Name:</div>
                <div>{{ $license->customer_name ?: '—' }}</div>

                <div style="color: var(--text-muted);">Customer Email:</div>
                <div>{{ $license->customer_email ?: '—' }}</div>

                <div style="color: var(--text-muted);">Administrative Notes:</div>
                <div style="white-space: pre-wrap;">{{ $license->notes ?: '—' }}</div>
            </div>
        </div>
    </div>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color);">
        <h2 class="card-title" style="margin-bottom: 0.25rem;">Audit Trail</h2>
        <p style="font-size: 0.825rem; color: var(--text-muted);">Immutable audit events recorded for this license lifecycle</p>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Actor</th>
                    <th>IP Address</th>
                    <th>Recorded At</th>
                    <th>Payload Details</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($license->logs as $log)
                    <tr>
                        <td>
                            @php
                                $eventVal = $log->event->value ?? $log->event;
                                $badgeColor = match($eventVal) {
                                    'LICENSE_CREATED' => 'badge-unused',
                                    'LICENSE_SUSPENDED' => 'badge-suspended',
                                    'LICENSE_UNSUSPENDED' => 'badge-active',
                                    'LICENSE_REVOKED' => 'badge-revoked',
                                    'LICENSE_RENEWED' => 'badge-active',
                                    'BINDING_RESET' => 'badge-unused',
                                    'LICENSE_EXPIRED' => 'badge-expired',
                                    default => 'badge-unused',
                                };
                            @endphp
                            <span class="badge {{ $badgeColor }}" style="font-size: 0.7rem;">
                                {{ $eventVal }}
                            </span>
                        </td>
                        <td>
                            <div>{{ $log->actor_type->value ?? $log->actor_type }}</div>
                            @if ($log->actor_user_id)
                                <div style="font-size: 0.75rem; color: var(--text-muted);">User ID: {{ $log->actor_user_id }}</div>
                            @endif
                        </td>
                        <td>
                            <code>{{ $log->ip_address ?? '—' }}</code>
                        </td>
                        <td>
                            <span>{{ $log->created_at ? $log->created_at->format('Y-m-d H:i:s') : '—' }}</span>
                        </td>
                        <td style="font-size: 0.8rem; color: var(--text-muted);">
                            @if ($log->payload)
                                <code style="font-size: 0.75rem; color: #94a3b8;">{{ json_encode($log->payload) }}</code>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2rem;">
                            No audit events found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

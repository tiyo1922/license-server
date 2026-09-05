@extends('layouts.admin')

@section('title', 'Application Details — ' . $application->name)

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Application Details</h1>
        <p class="page-subtitle">Configure API credentials, inspect issued licenses, and manage lifecycle for <span class="code-key">{{ $application->code }}</span></p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="{{ route('admin.applications.index') }}" class="btn btn-secondary">← Back to Applications</a>
        <a href="{{ route('admin.applications.edit', $application) }}" class="btn btn-secondary">✏️ Edit Metadata</a>
        <form action="{{ route('admin.applications.toggle-active', $application) }}" method="POST" style="display: inline;" onsubmit="return confirm('{{ $application->is_active ? 'Disable this application? All API authentication and license activations for this application will be immediately blocked.' : 'Enable this application? API operations will be restored.' }}');">
            @csrf
            @if ($application->is_active)
                <button type="submit" class="btn btn-secondary" style="border-color: #eab308; color: #fde047;">⏸️ Deactivate Application</button>
            @else
                <button type="submit" class="btn btn-primary" style="background-color: #22c55e; color: #0f172a; font-weight: 600;">▶️ Activate Application</button>
            @endif
        </form>
    </div>
</div>

{{-- Single-Reveal Plaintext API Secret Banner (Rendered exclusively in immediate HTTP response body) --}}
@if (isset($singleRevealSecret) && $singleRevealSecret)
    <div class="card" style="background-color: rgba(34, 197, 94, 0.1); border: 1px solid #22c55e; margin-bottom: 2rem;">
        <div>
            <h2 style="font-size: 1.15rem; font-weight: 700; color: #86efac; margin-bottom: 0.5rem;">
                🛡️ Newly Generated API Secret (Single Reveal)
            </h2>
            <p style="font-size: 0.875rem; color: #bbf7d0; margin-bottom: 1rem; line-height: 1.5;">
                <strong>Critical Security Notice:</strong> Copy this API Secret now. For database security, the plaintext secret is <strong>NEVER stored in the database</strong> (only a SHA-256 hash is retained). This credential will <strong>NEVER</strong> be displayed again after you leave or reload this page.
            </p>
            <div style="display: grid; grid-template-columns: 140px 1fr; row-gap: 0.75rem; margin-bottom: 1rem; font-size: 0.9rem;">
                <div style="color: #86efac; font-weight: 600;">Key ID (Header):</div>
                <div><code style="color: #ffffff; font-size: 1rem;">{{ $newApiKey->key_id }}</code></div>

                <div style="color: #86efac; font-weight: 600;">API Secret:</div>
                <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                    <span id="plaintext-secret-box" class="code-key" style="font-size: 1.1rem; padding: 0.4rem 0.85rem; color: #ffffff; background: #0b1120; border-color: #22c55e;">
                        {{ $singleRevealSecret }}
                    </span>
                    <button type="button" onclick="copySecret()" class="btn btn-primary" id="copy-secret-btn" style="background-color: #22c55e; color: #0f172a; font-weight: 600; padding: 0.4rem 0.85rem;">
                        📋 Copy Secret
                    </button>
                    <span id="copy-secret-feedback" style="font-size: 0.85rem; color: #86efac; display: none;">Copied to clipboard!</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        function copySecret() {
            const secretText = document.getElementById('plaintext-secret-box').innerText.trim();
            navigator.clipboard.writeText(secretText).then(() => {
                const feedback = document.getElementById('copy-secret-feedback');
                const btn = document.getElementById('copy-secret-btn');
                feedback.style.display = 'inline';
                btn.innerText = '✅ Copied!';
                setTimeout(() => {
                    feedback.style.display = 'none';
                    btn.innerText = '📋 Copy Secret';
                }, 3000);
            });
        }
    </script>
@endif

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
    <div class="card">
        <h2 class="card-title">Application Overview</h2>
        <div class="card-body">
            <div style="display: grid; grid-template-columns: 140px 1fr; row-gap: 0.85rem; font-size: 0.9rem;">
                <div style="color: var(--text-muted);">Application Code:</div>
                <div><span class="code-key">{{ $application->code }}</span></div>

                <div style="color: var(--text-muted);">Application Name:</div>
                <div><strong style="color: var(--text-main);">{{ $application->name }}</strong></div>

                <div style="color: var(--text-muted);">Status:</div>
                <div>
                    @if ($application->is_active)
                        <span class="badge badge-active">ACTIVE</span>
                    @else
                        <span class="badge badge-suspended">INACTIVE</span>
                        <span style="font-size: 0.75rem; color: #f87171; margin-left: 0.5rem;">(API calls rejected)</span>
                    @endif
                </div>

                <div style="color: var(--text-muted);">Description:</div>
                <div>{{ $application->description ?: '—' }}</div>

                <div style="color: var(--text-muted);">Registered At:</div>
                <div>{{ $application->created_at->format('Y-m-d H:i:s') }} UTC</div>
            </div>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">Generate New API Key</h2>
        <div class="card-body">
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                Issue machine credentials for client applications (WordPress, microservices). Multiple active keys are supported for zero-downtime rotation.
            </p>
            <form action="{{ route('admin.applications.keys.store', $application) }}" method="POST">
                @csrf
                <div class="form-group" style="margin-bottom: 0.85rem;">
                    <label for="key_name" class="form-label" style="font-size: 0.8rem;">Key Name / Description <span style="color: var(--danger);">*</span></label>
                    <input type="text" id="key_name" name="name" class="form-control" placeholder="e.g. Production Webhook Server, Staging Cluster" required maxlength="100">
                </div>
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label for="key_expires_at" class="form-label" style="font-size: 0.8rem;">Expiration Date & Time (UTC, Optional)</label>
                    <input type="datetime-local" id="key_expires_at" name="expires_at" class="form-control" style="max-width: 280px;">
                    <p class="form-text" style="font-size: 0.75rem;">Leave blank for non-expiring credentials.</p>
                </div>
                <button type="submit" class="btn btn-primary" style="font-size: 0.85rem;">+ Generate API Key</button>
            </form>
        </div>
    </div>
</div>

{{-- Application API Keys Table --}}
<div class="card" style="padding: 0; overflow: hidden; margin-bottom: 1.5rem;">
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 class="card-title" style="margin-bottom: 0.25rem;">Application API Keys</h2>
            <p style="font-size: 0.825rem; color: var(--text-muted);">Machine credentials authorized to call Activation & Verification APIs</p>
        </div>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Key Name</th>
                    <th>Key Identifier (X-Api-Key-Id)</th>
                    <th>Status</th>
                    <th>Created At</th>
                    <th>Expires At</th>
                    <th>Last Used At</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($application->apiKeys as $key)
                    <tr>
                        <td>
                            <strong style="color: var(--text-main);">{{ $key->name }}</strong>
                        </td>
                        <td>
                            <code class="code-key" style="font-size: 0.8rem;">{{ $key->key_id }}</code>
                        </td>
                        <td>
                            @if ($key->status->value === 'ACTIVE')
                                <span class="badge badge-active" style="font-size: 0.7rem;">ACTIVE</span>
                            @else
                                <span class="badge badge-revoked" style="font-size: 0.7rem;">REVOKED</span>
                            @endif
                        </td>
                        <td>
                            <span style="font-size: 0.85rem; color: var(--text-muted);">{{ $key->created_at->format('Y-m-d H:i') }} UTC</span>
                        </td>
                        <td>
                            @if ($key->expires_at)
                                <span style="font-size: 0.85rem; color: var(--text-main);">{{ $key->expires_at->format('Y-m-d H:i') }} UTC</span>
                            @else
                                <span style="font-size: 0.8rem; color: var(--text-muted);">Never (No Expiry)</span>
                            @endif
                        </td>
                        <td>
                            @if ($key->last_used_at)
                                <span style="font-size: 0.85rem; color: #38bdf8;">{{ $key->last_used_at->diffForHumans() }}</span>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">{{ $key->last_used_at->format('Y-m-d H:i') }} UTC</div>
                            @else
                                <span style="font-size: 0.8rem; color: var(--text-muted);">Never Used</span>
                            @endif
                        </td>
                        <td style="text-align: right; white-space: nowrap;">
                            @if ($key->status->value === 'ACTIVE')
                                <form action="{{ route('admin.applications.keys.revoke', [$application, $key]) }}" method="POST" style="display: inline;" onsubmit="return confirm('⚠️ Revoke API Key [{{ $key->key_id }}]? Any client application using this key will immediately fail authentication. Revocation is permanent.');">
                                    @csrf
                                    <button type="submit" class="btn btn-secondary" style="border-color: #ef4444; color: #fca5a5; padding: 0.25rem 0.6rem; font-size: 0.75rem;">🚫 Revoke Key</button>
                                </form>
                            @else
                                <span style="font-size: 0.75rem; color: #fca5a5;">🔒 Revoked</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2.5rem 1rem;">
                            No API keys issued for this application yet. Generate one above to enable client API integration.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Issued Licenses Summary --}}
<div class="card" style="padding: 0; overflow: hidden;">
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 class="card-title" style="margin-bottom: 0.25rem;">Recently Issued Licenses</h2>
            <p style="font-size: 0.825rem; color: var(--text-muted);">Latest client licenses bound to this application</p>
        </div>
        <div>
            <a href="{{ route('admin.licenses.create') }}" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;">+ Issue License</a>
        </div>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Masked License Key</th>
                    <th>Customer</th>
                    <th>Status</th>
                    <th>Active Domain Binding</th>
                    <th>Validity</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($application->licenses as $lic)
                    <tr>
                        <td>
                            <a href="{{ route('admin.licenses.show', $lic) }}" class="code-key" style="text-decoration: none; font-size: 0.85rem;">
                                {{ $lic->key_masked }}
                            </a>
                        </td>
                        <td>
                            <div style="font-size: 0.875rem;">{{ $lic->customer_name ?: '—' }}</div>
                            @if ($lic->customer_email)
                                <div style="font-size: 0.75rem; color: var(--text-muted);">{{ $lic->customer_email }}</div>
                            @endif
                        </td>
                        <td>
                            <span class="badge badge-{{ strtolower($lic->status->value) }}" style="font-size: 0.7rem;">
                                {{ $lic->status->value }}
                            </span>
                        </td>
                        <td>
                            @if ($lic->activation)
                                <span style="color: #38bdf8; font-size: 0.85rem;">{{ $lic->activation->canonical_domain }}</span>
                            @else
                                <span style="color: var(--text-muted); font-size: 0.8rem;">Unbound</span>
                            @endif
                        </td>
                        <td>
                            @if ($lic->expires_at)
                                <span style="font-size: 0.85rem;">{{ $lic->expires_at->format('Y-m-d H:i') }} UTC</span>
                            @else
                                <span style="color: #86efac; font-size: 0.8rem; font-weight: 500;">Lifetime</span>
                            @endif
                        </td>
                        <td style="text-align: right;">
                            <a href="{{ route('admin.licenses.show', $lic) }}" class="btn btn-secondary" style="padding: 0.25rem 0.55rem; font-size: 0.75rem;">Inspect</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2rem 1rem;">
                            No licenses issued for this application yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

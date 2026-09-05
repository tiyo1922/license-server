@extends('layouts.admin')

@section('title', 'Generate New License')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Generate New License</h1>
        <p class="page-subtitle">Create a cryptographically secure 80-bit serial for a client application</p>
    </div>
    <div>
        <a href="{{ route('admin.licenses.index') }}" class="btn btn-secondary">← Back to Licenses</a>
    </div>
</div>

@if ($errors->any())
    <div class="alert alert-danger">
        <strong>Please correct the following errors:</strong>
        <ul style="margin-top: 0.5rem; padding-left: 1.25rem;">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="card" style="max-width: 760px;">
    <form action="{{ route('admin.licenses.store') }}" method="POST">
        @csrf

        <div class="form-group">
            <label for="application_id" class="form-label">Target Application <span style="color: var(--danger);">*</span></label>
            <select id="application_id" name="application_id" class="form-control" required>
                <option value="">— Select Target Client Application —</option>
                @foreach ($applications as $app)
                    <option value="{{ $app->id }}" {{ old('application_id') == $app->id ? 'selected' : '' }}>
                        {{ $app->name }} (Code: {{ $app->code }})
                    </option>
                @endforeach
            </select>
            <p class="form-text">The license format and checksum will be bound to the application's unique code prefix.</p>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div class="form-group">
                <label for="customer_name" class="form-label">Customer / Organization Name</label>
                <input type="text" id="customer_name" name="customer_name" class="form-control" value="{{ old('customer_name') }}" placeholder="e.g. PT Sumber Protein Jogja" maxlength="100">
            </div>

            <div class="form-group">
                <label for="customer_email" class="form-label">Customer Contact Email</label>
                <input type="email" id="customer_email" name="customer_email" class="form-control" value="{{ old('customer_email') }}" placeholder="e.g. billing@sumberprotein.com" maxlength="100">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Validity / Expiration Strategy <span style="color: var(--danger);">*</span></label>
            <div style="display: flex; gap: 1.5rem; margin-top: 0.5rem; margin-bottom: 0.75rem;">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; color: var(--text-main);">
                    <input type="radio" name="validity_type" value="lifetime" {{ old('validity_type', 'lifetime') === 'lifetime' ? 'checked' : '' }} onchange="toggleExpirationInput()">
                    <span><strong>Lifetime License</strong> (No expiration timestamp)</span>
                </label>
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; color: var(--text-main);">
                    <input type="radio" name="validity_type" value="custom" {{ old('validity_type') === 'custom' ? 'checked' : '' }} onchange="toggleExpirationInput()">
                    <span><strong>Time-Bound License</strong> (Expires at specific date/time)</span>
                </label>
            </div>

            <div id="expiration-input-container" style="display: {{ old('validity_type') === 'custom' ? 'block' : 'none' }}; margin-top: 0.75rem;">
                <label for="expires_at" class="form-label" style="font-size: 0.8rem;">Expiration Date & Time (UTC)</label>
                <input type="datetime-local" id="expires_at" name="expires_at" class="form-control" style="max-width: 300px;" value="{{ old('expires_at') }}">
                <p class="form-text">Must be a future date/time relative to authoritative server UTC time.</p>
            </div>
        </div>

        <div class="form-group">
            <label for="notes" class="form-label">Administrative Notes (Optional)</label>
            <textarea id="notes" name="notes" class="form-control" rows="3" placeholder="Add administrative or contract references..." maxlength="1000">{{ old('notes') }}</textarea>
            <p class="form-text" style="color: var(--text-muted);">⚠️ Do not store passwords, API secrets, or private keys in notes.</p>
        </div>

        <div style="background-color: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.2); border-radius: 0.375rem; padding: 0.85rem 1rem; margin-bottom: 1.5rem;">
            <p style="font-size: 0.85rem; color: #93c5fd; line-height: 1.4;">
                <strong>Security Notice:</strong> The plaintext license key will be generated using 80 bits of cryptographic entropy and displayed <strong>only once</strong> upon creation. Only the SHA-256 hash and masked key will be stored in the database.
            </p>
        </div>

        <div style="display: flex; gap: 1rem; align-items: center;">
            <button type="submit" class="btn btn-primary" style="padding: 0.65rem 1.5rem; font-size: 0.95rem;">Generate License Key</button>
            <a href="{{ route('admin.licenses.index') }}" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<script>
    function toggleExpirationInput() {
        const isCustom = document.querySelector('input[name="validity_type"]:checked').value === 'custom';
        const container = document.getElementById('expiration-input-container');
        container.style.display = isCustom ? 'block' : 'none';
    }
</script>
@endsection

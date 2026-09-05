@extends('layouts.admin')

@section('title', 'Register Application')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Register Application</h1>
        <p class="page-subtitle">Configure a new client application to participate in the license authority</p>
    </div>
    <div>
        <a href="{{ route('admin.applications.index') }}" class="btn btn-secondary">← Back to Applications</a>
    </div>
</div>

<div class="card" style="max-width: 650px;">
    <h2 class="card-title">Application Configuration</h2>
    <div class="card-body">
        <form action="{{ route('admin.applications.store') }}" method="POST">
            @csrf

            <div class="form-group">
                <label for="code" class="form-label">Application Code (Identifier) <span style="color: var(--danger);">*</span></label>
                <input type="text" id="code" name="code" class="form-control" value="{{ old('code') }}" placeholder="e.g. SUMBER-PROT, WIDGET-PRO" required maxlength="50" style="text-transform: uppercase;">
                <p class="form-text">
                    <strong>Important:</strong> Must be unique, 2–50 uppercase characters, alphanumeric, hyphens, and underscores only.
                    <br><span style="color: #fca5a5;">⚠️ Application Code is <strong>strictly immutable</strong> after creation as it binds to license serials and cryptographic token audience claims.</span>
                </p>
                @error('code')
                    <p style="color: var(--danger); font-size: 0.8rem; margin-top: 0.25rem;">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group">
                <label for="name" class="form-label">Application Name <span style="color: var(--danger);">*</span></label>
                <input type="text" id="name" name="name" class="form-control" value="{{ old('name') }}" placeholder="e.g. Sumber Protein Suite" required maxlength="100">
                <p class="form-text">Human-readable name for administrative identification.</p>
                @error('name')
                    <p style="color: var(--danger); font-size: 0.8rem; margin-top: 0.25rem;">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group">
                <label for="description" class="form-label">Description / Notes (Optional)</label>
                <textarea id="description" name="description" class="form-control" rows="3" placeholder="e.g. Production enterprise plugin deployed across client WordPress instances" maxlength="255">{{ old('description') }}</textarea>
                <p class="form-text">Optional administrative description (max 255 characters).</p>
                @error('description')
                    <p style="color: var(--danger); font-size: 0.8rem; margin-top: 0.25rem;">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" name="is_active" value="1" {{ old('is_active', '1') === '1' ? 'checked' : '' }}>
                    <span style="font-size: 0.875rem; color: var(--text-main);">Activate application immediately upon creation</span>
                </label>
            </div>

            <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="submit" class="btn btn-primary">Register Application</button>
                <a href="{{ route('admin.applications.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection

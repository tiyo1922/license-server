@extends('layouts.admin')

@section('title', 'Edit Application — ' . $application->name)

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Edit Application</h1>
        <p class="page-subtitle">Update metadata for <span class="code-key">{{ $application->code }}</span></p>
    </div>
    <div>
        <a href="{{ route('admin.applications.show', $application) }}" class="btn btn-secondary">← Back to Application</a>
    </div>
</div>

<div class="card" style="max-width: 650px;">
    <h2 class="card-title">Application Metadata</h2>
    <div class="card-body">
        <form action="{{ route('admin.applications.update', $application) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="form-group">
                <label class="form-label">Application Code (Identifier)</label>
                <input type="text" class="form-control" value="{{ $application->code }}" disabled style="opacity: 0.7; cursor: not-allowed; background-color: #0b1120;">
                <p class="form-text" style="color: #94a3b8;">
                    🔒 Application Code is <strong>strictly immutable</strong>. It cannot be altered post-creation because existing issued license serials and cryptographic token audience claims depend on this exact identifier.
                </p>
            </div>

            <div class="form-group">
                <label for="name" class="form-label">Application Name <span style="color: var(--danger);">*</span></label>
                <input type="text" id="name" name="name" class="form-control" value="{{ old('name', $application->name) }}" required maxlength="100">
                <p class="form-text">Human-readable name for administrative identification.</p>
                @error('name')
                    <p style="color: var(--danger); font-size: 0.8rem; margin-top: 0.25rem;">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group">
                <label for="description" class="form-label">Description / Notes (Optional)</label>
                <textarea id="description" name="description" class="form-control" rows="3" maxlength="255">{{ old('description', $application->description) }}</textarea>
                <p class="form-text">Optional administrative description (max 255 characters).</p>
                @error('description')
                    <p style="color: var(--danger); font-size: 0.8rem; margin-top: 0.25rem;">{{ $message }}</p>
                @enderror
            </div>

            <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="{{ route('admin.applications.show', $application) }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection

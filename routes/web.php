<?php

use App\Http\Controllers\Admin\ApplicationApiKeyController;
use App\Http\Controllers\Admin\ApplicationController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LicenseController;
use App\Http\Controllers\Admin\LicenseLifecycleController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Root URL: Safe redirect to dashboard if authenticated, or login if guest
Route::get('/', function () {
    return Auth::guard('web')->check()
        ? redirect()->route('admin.dashboard')
        : redirect()->route('login');
});

// Guest Authentication Routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

// Authenticated Admin Routes
Route::middleware('auth:web')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/admin/dashboard', [DashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('/admin/audit-logs', [AuditLogController::class, 'index'])->name('admin.audit-logs.index');

    // Application Management Routes
    Route::get('/admin/applications', [ApplicationController::class, 'index'])->name('admin.applications.index');
    Route::get('/admin/applications/create', [ApplicationController::class, 'create'])->name('admin.applications.create');
    Route::post('/admin/applications', [ApplicationController::class, 'store'])->name('admin.applications.store');
    Route::get('/admin/applications/{application}', [ApplicationController::class, 'show'])->name('admin.applications.show');
    Route::get('/admin/applications/{application}/edit', [ApplicationController::class, 'edit'])->name('admin.applications.edit');
    Route::put('/admin/applications/{application}', [ApplicationController::class, 'update'])->name('admin.applications.update');
    Route::post('/admin/applications/{application}/toggle-active', [ApplicationController::class, 'toggleActive'])->name('admin.applications.toggle-active');

    // Application API Key Management Routes
    Route::post('/admin/applications/{application}/keys', [ApplicationApiKeyController::class, 'store'])->name('admin.applications.keys.store');
    Route::post('/admin/applications/{application}/keys/{key}/revoke', [ApplicationApiKeyController::class, 'revoke'])->name('admin.applications.keys.revoke');

    // License Management & Generation Routes
    Route::get('/admin/licenses', [LicenseController::class, 'index'])->name('admin.licenses.index');
    Route::get('/admin/licenses/create', [LicenseController::class, 'create'])->name('admin.licenses.create');
    Route::post('/admin/licenses', [LicenseController::class, 'store'])->name('admin.licenses.store');
    Route::get('/admin/licenses/{license}', [LicenseController::class, 'show'])->name('admin.licenses.show');

    // License Lifecycle Action Routes
    Route::post('/admin/licenses/{license}/suspend', [LicenseLifecycleController::class, 'suspend'])->name('admin.licenses.suspend');
    Route::post('/admin/licenses/{license}/unsuspend', [LicenseLifecycleController::class, 'unsuspend'])->name('admin.licenses.unsuspend');
    Route::post('/admin/licenses/{license}/revoke', [LicenseLifecycleController::class, 'revoke'])->name('admin.licenses.revoke');
    Route::post('/admin/licenses/{license}/reset-binding', [LicenseLifecycleController::class, 'resetBinding'])->name('admin.licenses.reset-binding');
    Route::post('/admin/licenses/{license}/renew', [LicenseLifecycleController::class, 'renew'])->name('admin.licenses.renew');
});


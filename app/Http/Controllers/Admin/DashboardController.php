<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApplicationApiKeyStatus;
use App\Enums\LicenseStatus;
use App\Http\Controllers\Controller;
use App\Models\Activation;
use App\Models\Application;
use App\Models\ApplicationApiKey;
use App\Models\License;
use App\Models\LicenseLog;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Display the operational admin dashboard.
     */
    public function index(): View
    {
        // 1. Application & API Key Metrics
        $totalApplications = Application::count();
        $activeApplications = Application::where('is_active', true)->count();
        $activeApiKeys = ApplicationApiKey::where('status', ApplicationApiKeyStatus::ACTIVE)->count();

        // 2. License Status Breakdown (grouped query to avoid multiple count queries)
        $licenseCountsByStatus = License::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $totalLicenses = array_sum($licenseCountsByStatus);
        $activeLicenses = $licenseCountsByStatus[LicenseStatus::ACTIVE->value] ?? 0;
        $suspendedLicenses = $licenseCountsByStatus[LicenseStatus::SUSPENDED->value] ?? 0;
        $revokedLicenses = $licenseCountsByStatus[LicenseStatus::REVOKED->value] ?? 0;
        $expiredLicenses = $licenseCountsByStatus[LicenseStatus::EXPIRED->value] ?? 0;
        $unusedLicenses = $licenseCountsByStatus[LicenseStatus::UNUSED->value] ?? 0;

        // 3. Activation Metric
        $totalActivations = Activation::count();

        // 4. Recent Audit Activity Stream
        $recentLogs = LicenseLog::query()
            ->with(['application', 'license', 'actorUser'])
            ->latest('id')
            ->limit(12)
            ->get();

        return view('admin.dashboard', compact(
            'totalApplications',
            'activeApplications',
            'activeApiKeys',
            'totalLicenses',
            'activeLicenses',
            'suspendedLicenses',
            'revokedLicenses',
            'expiredLicenses',
            'unusedLicenses',
            'totalActivations',
            'recentLogs'
        ));
    }
}

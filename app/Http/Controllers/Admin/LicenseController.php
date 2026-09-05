<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreLicenseRequest;
use App\Models\Application;
use App\Models\License;
use App\Services\License\LicenseCreationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LicenseController extends Controller
{
    /**
     * Display a listing of the licenses.
     */
    public function index(Request $request): View
    {
        $query = License::with('application')->latest();

        if ($request->filled('application_id')) {
            $query->where('application_id', $request->input('application_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('key_masked', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%");
            });
        }

        $licenses = $query->paginate(15)->withQueryString();
        $applications = Application::orderBy('name')->get();

        return view('admin.licenses.index', compact('licenses', 'applications'));
    }

    /**
     * Show the form for creating a new license.
     */
    public function create(): View
    {
        $applications = Application::where('is_active', true)->orderBy('name')->get();

        return view('admin.licenses.create', compact('applications'));
    }

    /**
     * Store a newly created license and render immediate single-reveal response.
     *
     * The plaintext license key is returned exclusively in the immediate HTTP response body
     * and is strictly excluded from persistent storage (sessions table, database, cache, or logs).
     */
    public function store(StoreLicenseRequest $request, LicenseCreationService $creationService): View
    {
        $application = Application::findOrFail($request->input('application_id'));

        $result = $creationService->createLicense($application, $request->licenseData());

        $license = $result['license'];
        $singleRevealKey = $result['plaintextKey'];

        $license->load(['application', 'logs' => fn ($q) => $q->latest()->limit(20)]);

        return view('admin.licenses.show', [
            'license' => $license,
            'singleRevealKey' => $singleRevealKey,
            'success' => 'License generated successfully.',
        ]);
    }

    /**
     * Display the specified license in masked format.
     *
     * Direct GET requests always receive $singleRevealKey = null to ensure that
     * only the masked key representation is displayed.
     */
    public function show(License $license): View
    {
        $license->load(['application', 'logs' => fn ($q) => $q->latest()->limit(20)]);

        $singleRevealKey = null;

        return view('admin.licenses.show', compact('license', 'singleRevealKey'));
    }
}


<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreApplicationRequest;
use App\Http\Requests\Admin\ToggleApplicationActiveRequest;
use App\Http\Requests\Admin\UpdateApplicationRequest;
use App\Models\Application;
use App\Services\License\ApplicationManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApplicationController extends Controller
{
    /**
     * Display a listing of registered applications.
     */
    public function index(Request $request): View
    {
        $query = Application::withCount([
            'apiKeys',
            'apiKeys as active_api_keys_count' => function ($q) {
                $q->where('status', 'ACTIVE');
            },
            'licenses',
        ])->latest();

        if ($request->filled('status')) {
            $status = $request->input('status');
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $applications = $query->paginate(15)->withQueryString();

        return view('admin.applications.index', compact('applications'));
    }

    /**
     * Show the form for creating a new application.
     */
    public function create(): View
    {
        return view('admin.applications.create');
    }

    /**
     * Store a newly created application.
     */
    public function store(StoreApplicationRequest $request, ApplicationManagementService $service): RedirectResponse
    {
        $application = $service->createApplication($request->applicationData());

        return redirect()
            ->route('admin.applications.show', $application)
            ->with('success', "Application [{$application->name}] registered successfully.");
    }

    /**
     * Display the specified application details and API keys.
     */
    public function show(Application $application): View
    {
        $application->load([
            'apiKeys' => fn ($q) => $q->latest(),
            'licenses' => fn ($q) => $q->with('activation')->latest()->limit(10),
        ]);

        $singleRevealSecret = null;

        return view('admin.applications.show', compact('application', 'singleRevealSecret'));
    }

    /**
     * Show the form for editing the specified application.
     */
    public function edit(Application $application): View
    {
        return view('admin.applications.edit', compact('application'));
    }

    /**
     * Update the specified application metadata.
     */
    public function update(
        UpdateApplicationRequest $request,
        Application $application,
        ApplicationManagementService $service
    ): RedirectResponse {
        $service->updateApplication($application, $request->applicationData());

        return redirect()
            ->route('admin.applications.show', $application)
            ->with('success', "Application [{$application->name}] updated successfully.");
    }

    /**
     * Toggle the active status of an application.
     */
    public function toggleActive(
        ToggleApplicationActiveRequest $request,
        Application $application,
        ApplicationManagementService $service
    ): RedirectResponse {
        $updated = $service->toggleActive($application, null, $request->input('reason'));
        $statusText = $updated->is_active ? 'enabled' : 'disabled';

        return redirect()
            ->route('admin.applications.show', $application)
            ->with('success', "Application [{$application->name}] has been {$statusText}.");
    }
}

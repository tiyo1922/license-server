<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RevokeApplicationApiKeyRequest;
use App\Http\Requests\Admin\StoreApplicationApiKeyRequest;
use App\Models\Application;
use App\Models\ApplicationApiKey;
use App\Services\License\ApiKeyManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ApplicationApiKeyController extends Controller
{
    /**
     * Store a newly created API key and render the immediate single-reveal response.
     *
     * The plaintext API secret is rendered exclusively in this immediate HTTP response body
     * and is strictly excluded from persistent storage, sessions, cache, or logs.
     */
    public function store(
        StoreApplicationApiKeyRequest $request,
        Application $application,
        ApiKeyManagementService $service
    ): View {
        $result = $service->createApiKey($application, $request->apiKeyData());

        $newApiKey = $result['apiKey'];
        $singleRevealSecret = $result['plaintextSecret'];

        $application->load([
            'apiKeys' => fn ($q) => $q->latest(),
            'licenses' => fn ($q) => $q->with('activation')->latest()->limit(10),
        ]);

        return view('admin.applications.show', [
            'application' => $application,
            'singleRevealSecret' => $singleRevealSecret,
            'newApiKey' => $newApiKey,
            'success' => "API Key [{$newApiKey->name}] generated successfully. Copy the secret now.",
        ]);
    }

    /**
     * Terminally revoke an application API key.
     */
    public function revoke(
        RevokeApplicationApiKeyRequest $request,
        Application $application,
        ApplicationApiKey $key,
        ApiKeyManagementService $service
    ): RedirectResponse {
        $service->revokeApiKey($application, $key, $request->input('reason'));

        return redirect()
            ->route('admin.applications.show', $application)
            ->with('success', "API Key [{$key->key_id}] has been permanently revoked.");
    }
}

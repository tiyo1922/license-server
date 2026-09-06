<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LifecycleActionRequest;
use App\Http\Requests\Admin\RenewLicenseRequest;
use App\Models\License;
use App\Services\License\LicenseLifecycleService;
use App\Exceptions\InvalidStateTransitionException;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

class LicenseLifecycleController extends Controller
{
    /**
     * Suspend an active license.
     */
    public function suspend(LifecycleActionRequest $request, License $license, LicenseLifecycleService $service): RedirectResponse
    {
        try {
            $service->suspend($license, $request->input('reason'));

            return redirect()
                ->route('admin.licenses.show', $license)
                ->with('success', 'License suspended successfully.');
        } catch (InvalidStateTransitionException $e) {
            return redirect()
                ->route('admin.licenses.show', $license)
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Unsuspend a suspended license.
     */
    public function unsuspend(LifecycleActionRequest $request, License $license, LicenseLifecycleService $service): RedirectResponse
    {
        try {
            $service->unsuspend($license, $request->input('reason'));

            return redirect()
                ->route('admin.licenses.show', $license)
                ->with('success', 'License unsuspended successfully.');
        } catch (InvalidStateTransitionException $e) {
            return redirect()
                ->route('admin.licenses.show', $license)
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Permanently revoke a license.
     */
    public function revoke(LifecycleActionRequest $request, License $license, LicenseLifecycleService $service): RedirectResponse
    {
        try {
            $service->revoke($license, $request->input('reason'));

            return redirect()
                ->route('admin.licenses.show', $license)
                ->with('success', 'License permanently revoked.');
        } catch (InvalidStateTransitionException $e) {
            return redirect()
                ->route('admin.licenses.show', $license)
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Reset domain activation binding for a suspended license.
     */
    public function resetBinding(LifecycleActionRequest $request, License $license, LicenseLifecycleService $service): RedirectResponse
    {
        try {
            $service->resetBinding($license, $request->input('reason'));

            return redirect()
                ->route('admin.licenses.show', $license)
                ->with('success', 'Activation binding reset successfully. License returned to UNUSED.');
        } catch (InvalidStateTransitionException $e) {
            return redirect()
                ->route('admin.licenses.show', $license)
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Renew or extend an expired, active, or unused license validity.
     */
    public function renew(RenewLicenseRequest $request, License $license, LicenseLifecycleService $service): RedirectResponse
    {
        try {
            $service->renew(
                license: $license,
                validityType: $request->validityType() ?? 'custom',
                newExpiresAt: $request->newExpiresAt(),
                reason: $request->input('reason')
            );

            return redirect()
                ->route('admin.licenses.show', $license)
                ->with('success', 'License validity updated successfully.');
        } catch (InvalidStateTransitionException|InvalidArgumentException $e) {
            return redirect()
                ->route('admin.licenses.show', $license)
                ->with('error', $e->getMessage());
        }
    }
}

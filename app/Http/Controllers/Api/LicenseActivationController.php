<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ActivateLicenseRequest;
use App\Models\ApplicationApiKey;
use App\Services\License\LicenseActivationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class LicenseActivationController extends Controller
{
    public function __construct(
        private LicenseActivationService $activationService
    ) {}

    /**
     * Activate a license for a canonical client domain.
     */
    public function activate(ActivateLicenseRequest $request): JsonResponse
    {
        /** @var ApplicationApiKey $apiKey */
        $apiKey = $request->attributes->get('authenticated_api_key');

        $result = $this->activationService->activate(
            apiKey: $apiKey,
            rawLicenseKey: (string) $request->input('license_key'),
            rawDomain: (string) $request->input('domain'),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent()
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ], Response::HTTP_OK);
    }
}

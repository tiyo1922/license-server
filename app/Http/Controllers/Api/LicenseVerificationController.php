<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\VerifyLicenseRequest;
use App\Models\ApplicationApiKey;
use App\Services\License\LicenseVerificationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class LicenseVerificationController extends Controller
{
    public function __construct(
        private LicenseVerificationService $verificationService
    ) {}

    /**
     * Verify an activated license token online and perform rolling refresh when eligible.
     */
    public function verify(VerifyLicenseRequest $request): JsonResponse
    {
        /** @var ApplicationApiKey $apiKey */
        $apiKey = $request->attributes->get('authenticated_api_key');

        $result = $this->verificationService->verify(
            apiKey: $apiKey,
            rawToken: (string) $request->input('token'),
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

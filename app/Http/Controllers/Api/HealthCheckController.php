<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\License\Token\TokenSignerInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HealthCheckController extends Controller
{
    /**
     * Check high-level operational readiness of the license authority.
     *
     * Returns 200 OK when database and signing subsystems are functional.
     * Returns 503 Service Unavailable if any core subsystem is degraded.
     * Leaks no private keys, environment variables, or internal paths.
     */
    public function check(): JsonResponse
    {
        $dbStatus = 'OK';
        $signerStatus = 'READY';
        $isHealthy = true;

        // 1. Check Database Connectivity
        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            $dbStatus = 'ERROR';
            $isHealthy = false;
        }

        // 2. Check Ed25519 Token Signer Readiness
        try {
            $signer = app(TokenSignerInterface::class);
            if (! $signer || empty($signer->getKeyId())) {
                $signerStatus = 'ERROR';
                $isHealthy = false;
            }
        } catch (Throwable) {
            $signerStatus = 'ERROR';
            $isHealthy = false;
        }

        $statusCode = $isHealthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return response()->json([
            'status' => $isHealthy ? 'UP' : 'DOWN',
            'timestamp' => now('UTC')->toIso8601String(),
            'services' => [
                'database' => $dbStatus,
                'signer' => $signerStatus,
            ],
        ], $statusCode);
    }
}

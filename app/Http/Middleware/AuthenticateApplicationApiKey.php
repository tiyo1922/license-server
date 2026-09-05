<?php

namespace App\Http\Middleware;

use App\Enums\ApplicationApiKeyStatus;
use App\Models\ApplicationApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApplicationApiKey
{
    /**
     * Handle an incoming request authenticated via Application API Key.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $keyId = $request->header('X-Api-Key-Id');
        $secret = $request->header('X-Api-Secret');

        if (! is_string($keyId) || ! is_string($secret) || trim($keyId) === '' || trim($secret) === '') {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'API credentials missing or invalid.',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        $apiKey = ApplicationApiKey::with('application')->where('key_id', $keyId)->first();

        if (! $apiKey) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Invalid API credentials.',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        if ($apiKey->status !== ApplicationApiKeyStatus::ACTIVE) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'API key has been revoked.',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        if ($apiKey->expires_at && now('UTC')->gte($apiKey->expires_at)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'API key has expired.',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        $providedHash = hash('sha256', $secret);
        if (! hash_equals($apiKey->key_hash, $providedHash)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Invalid API credentials.',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (! $apiKey->application || ! $apiKey->application->is_active) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Application is inactive.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        // Coalesced last_used_at update (maximum 1 write per 5 minutes per key)
        $serverNow = now('UTC');
        if ($apiKey->last_used_at === null || $apiKey->last_used_at->lt($serverNow->copy()->subMinutes(5))) {
            $apiKey->update(['last_used_at' => $serverNow]);
        }

        // Attach authenticated API key instance to request
        $request->attributes->set('authenticated_api_key', $apiKey);

        return $next($request);
    }
}

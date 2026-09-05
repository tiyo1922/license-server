<?php

namespace App\Services\License;

use App\Enums\LicenseLogActorType;
use App\Enums\LicenseLogEvent;
use App\Enums\LicenseStatus;
use App\Exceptions\DomainCanonicalizationException;
use App\Exceptions\LicenseVerificationException;
use App\Models\Activation;
use App\Models\ApplicationApiKey;
use App\Models\LicenseLog;
use App\Services\License\Token\TokenSignerInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LicenseVerificationService
{
    public function __construct(
        private DomainCanonicalizer $domainCanonicalizer,
        private TokenSignerInterface $tokenSigner
    ) {}

    /**
     * Verify an activated license token online and perform rolling refresh when eligible.
     *
     * @param ApplicationApiKey $apiKey
     * @param string $rawToken
     * @param string $rawDomain
     * @param string|null $ipAddress
     * @param string|null $userAgent
     * @return array<string, mixed>
     *
     * @throws LicenseVerificationException
     */
    public function verify(
        ApplicationApiKey $apiKey,
        string $rawToken,
        string $rawDomain,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        $serverNow = now('UTC');

        // 1. Canonicalize request domain
        try {
            $canonicalDomain = $this->domainCanonicalizer->canonicalize($rawDomain);
        } catch (DomainCanonicalizationException $e) {
            $this->logVerificationFailure(
                applicationId: $apiKey->application_id,
                licenseId: null,
                actorKeyId: $apiKey->key_id,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                reason: 'INVALID_DOMAIN',
                domain: null,
                serverNow: $serverNow
            );

            throw new LicenseVerificationException('The provided domain is invalid or malformed.', 'INVALID_DOMAIN', 422);
        }

        // 2. Cryptographically verify token structure, algorithm, kid, and signature
        try {
            $payload = $this->tokenSigner->verify($rawToken);
        } catch (RuntimeException $e) {
            $this->logVerificationFailure(
                applicationId: $apiKey->application_id,
                licenseId: null,
                actorKeyId: $apiKey->key_id,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                reason: 'TOKEN_VERIFICATION_FAILED',
                domain: $canonicalDomain,
                serverNow: $serverNow
            );

            $msg = $e->getMessage();
            if (str_contains($msg, 'header algorithm or type')) {
                throw new LicenseVerificationException('Unsupported token header algorithm or type.', 'INVALID_TOKEN_HEADER', 422);
            }
            if (str_contains($msg, 'key identifier')) {
                throw new LicenseVerificationException('Unknown or unsupported key identifier (kid).', 'TOKEN_KEY_UNKNOWN', 422);
            }
            if (str_contains($msg, 'Invalid token signature')) {
                throw new LicenseVerificationException('Token signature is invalid or has been tampered with.', 'INVALID_TOKEN_SIGNATURE', 422);
            }
            if (str_contains($msg, 'Malformed token structure')) {
                throw new LicenseVerificationException('Malformed token structure.', 'INVALID_TOKEN', 422);
            }

            throw new LicenseVerificationException('The provided token is invalid.', 'INVALID_TOKEN', 422);
        }

        // 3. Validate required claims structure
        $requiredClaims = ['jti', 'iss', 'aud', 'sub', 'dom', 'iat', 'nbf', 'exp'];
        foreach ($requiredClaims as $claim) {
            if (! array_key_exists($claim, $payload)) {
                $this->logVerificationFailure(
                    applicationId: $apiKey->application_id,
                    licenseId: null,
                    actorKeyId: $apiKey->key_id,
                    ipAddress: $ipAddress,
                    userAgent: $userAgent,
                    reason: 'MISSING_REQUIRED_CLAIMS',
                    domain: $canonicalDomain,
                    serverNow: $serverNow
                );

                throw new LicenseVerificationException('Token payload is missing required claims.', 'INVALID_TOKEN_CLAIMS', 422);
            }
        }

        // 4. Validate Issuer claim against configured authoritative issuer
        $configuredIssuer = (string) config('license.issuer', 'license.katresnanku.com');
        if (! hash_equals($configuredIssuer, (string) $payload['iss'])) {
            $this->logVerificationFailure(
                applicationId: $apiKey->application_id,
                licenseId: null,
                actorKeyId: $apiKey->key_id,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                reason: 'TOKEN_ISSUER_MISMATCH',
                domain: $canonicalDomain,
                serverNow: $serverNow,
                tokenId: (string) $payload['jti']
            );

            throw new LicenseVerificationException('Token issuer mismatch.', 'TOKEN_ISSUER_MISMATCH', 422);
        }

        // 5. Validate Audience claim against authenticated application code
        $expectedAudience = (string) $apiKey->application->code;
        if (! hash_equals($expectedAudience, (string) $payload['aud'])) {
            $this->logVerificationFailure(
                applicationId: $apiKey->application_id,
                licenseId: null,
                actorKeyId: $apiKey->key_id,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                reason: 'TOKEN_AUDIENCE_MISMATCH',
                domain: $canonicalDomain,
                serverNow: $serverNow,
                tokenId: (string) $payload['jti']
            );

            throw new LicenseVerificationException('Token audience does not match authenticated application.', 'TOKEN_AUDIENCE_MISMATCH', 422);
        }

        // 6. Validate Domain claim against canonical domain
        if (! hash_equals($canonicalDomain, (string) $payload['dom'])) {
            $this->logVerificationFailure(
                applicationId: $apiKey->application_id,
                licenseId: null,
                actorKeyId: $apiKey->key_id,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                reason: 'TOKEN_DOMAIN_MISMATCH',
                domain: $canonicalDomain,
                serverNow: $serverNow,
                tokenId: (string) $payload['jti']
            );

            throw new LicenseVerificationException('Token domain does not match requested canonical domain.', 'TOKEN_DOMAIN_MISMATCH', 422);
        }

        // 7. Authoritative Time Claims validation
        $serverTimestamp = $serverNow->timestamp;

        if ($serverTimestamp < (int) $payload['nbf']) {
            $this->logVerificationFailure(
                applicationId: $apiKey->application_id,
                licenseId: null,
                actorKeyId: $apiKey->key_id,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                reason: 'TOKEN_NOT_YET_VALID',
                domain: $canonicalDomain,
                serverNow: $serverNow,
                tokenId: (string) $payload['jti']
            );

            throw new LicenseVerificationException('Token is not yet active.', 'TOKEN_NOT_YET_VALID', 422);
        }

        if ($serverTimestamp >= (int) $payload['exp']) {
            $this->logVerificationFailure(
                applicationId: $apiKey->application_id,
                licenseId: null,
                actorKeyId: $apiKey->key_id,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                reason: 'TOKEN_EXPIRED',
                domain: $canonicalDomain,
                serverNow: $serverNow,
                tokenId: (string) $payload['jti']
            );

            throw new LicenseVerificationException('Token has expired.', 'TOKEN_EXPIRED', 403);
        }

        // 8. Database Concurrency Boundary & State Validation
        $failure = null;
        $result = null;

        DB::transaction(function () use (
            $apiKey, $payload, $rawToken, $canonicalDomain, $ipAddress, $userAgent, $serverNow,
            &$failure, &$result
        ) {
            $jti = (string) $payload['jti'];

            // Find activation record by current token generation identifier
            $lockedActivation = Activation::with(['license.application'])
                ->where('token_id', $jti)
                ->lockForUpdate()
                ->first();

            // Check if token generation exists and is active
            if ($lockedActivation === null) {
                $failure = [
                    'license_id' => null,
                    'reason' => 'TOKEN_SUPERSEDED',
                    'message' => 'The provided token is invalid or has been superseded.',
                    'code' => 'TOKEN_SUPERSEDED',
                    'status' => 403,
                    'token_id' => $jti,
                ];
                return;
            }

            // Domain binding check on activation record
            if (! hash_equals($lockedActivation->canonical_domain, $canonicalDomain)) {
                $failure = [
                    'license_id' => $lockedActivation->license_id,
                    'reason' => 'TOKEN_DOMAIN_MISMATCH',
                    'message' => 'Token domain binding does not match registered activation domain.',
                    'code' => 'TOKEN_DOMAIN_MISMATCH',
                    'status' => 409,
                    'token_id' => $jti,
                ];
                return;
            }

            $license = $lockedActivation->license;

            // Application boundary enforcement
            if ($license->application_id !== $apiKey->application_id) {
                $failure = [
                    'license_id' => $license->id,
                    'reason' => 'LICENSE_APPLICATION_MISMATCH',
                    'message' => 'The license does not belong to this application.',
                    'code' => 'LICENSE_APPLICATION_MISMATCH',
                    'status' => 422,
                    'token_id' => $jti,
                ];
                return;
            }

            $authoritativeStatus = $license->getAuthoritativeStatus($serverNow);

            // Terminal REVOKED status
            if ($authoritativeStatus === LicenseStatus::REVOKED) {
                $failure = [
                    'license_id' => $license->id,
                    'reason' => 'LICENSE_REVOKED',
                    'message' => 'This license has been permanently revoked.',
                    'code' => 'LICENSE_REVOKED',
                    'status' => 403,
                    'token_id' => $jti,
                ];
                return;
            }

            // SUSPENDED status
            if ($authoritativeStatus === LicenseStatus::SUSPENDED) {
                $failure = [
                    'license_id' => $license->id,
                    'reason' => 'LICENSE_SUSPENDED',
                    'message' => 'This license is currently suspended.',
                    'code' => 'LICENSE_SUSPENDED',
                    'status' => 403,
                    'token_id' => $jti,
                ];
                return;
            }

            // Authoritative EXPIRED status
            if ($authoritativeStatus === LicenseStatus::EXPIRED) {
                $failure = [
                    'license_id' => $license->id,
                    'reason' => 'LICENSE_EXPIRED',
                    'message' => 'This license has expired.',
                    'code' => 'LICENSE_EXPIRED',
                    'status' => 403,
                    'token_id' => $jti,
                ];
                return;
            }

            // Token-license expiry consistency: token must never outlive license
            if ($license->expires_at !== null && (int) $payload['exp'] > $license->expires_at->timestamp) {
                $failure = [
                    'license_id' => $license->id,
                    'reason' => 'TOKEN_EXPIRY_MISMATCH',
                    'message' => 'Token lifetime exceeds license expiration timestamp.',
                    'code' => 'TOKEN_EXPIRY_MISMATCH',
                    'status' => 422,
                    'token_id' => $jti,
                ];
                return;
            }

            // 9. Rolling Token Refresh Calculation
            $iat = (int) $payload['iat'];
            $exp = (int) $payload['exp'];
            $totalTtl = $exp - $iat;
            $remainingTtl = $exp - $serverNow->timestamp;
            $thresholdRatio = (float) config('license.rolling_refresh_threshold_percentage', 0.50);

            $shouldRefresh = ($totalTtl > 0) && ($remainingTtl < ($thresholdRatio * $totalTtl));

            if (! $shouldRefresh) {
                // No refresh needed: update last_verified_at atomically
                $lockedActivation->update([
                    'last_verified_at' => $serverNow,
                    'ip_address' => $ipAddress,
                ]);

                LicenseLog::create([
                    'application_id' => $license->application_id,
                    'license_id' => $license->id,
                    'event' => LicenseLogEvent::VERIFICATION_SUCCESS,
                    'actor_type' => LicenseLogActorType::CLIENT,
                    'actor_user_id' => null,
                    'actor_key_id' => $apiKey->key_id,
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                    'payload' => [
                        'domain' => $canonicalDomain,
                        'token_id' => $jti,
                        'refreshed' => false,
                    ],
                    'created_at' => $serverNow,
                ]);

                $tokenExpiresAt = (new \DateTimeImmutable('@' . $exp))->format(\DateTimeInterface::ATOM);

                $result = [
                    'valid' => true,
                    'status' => LicenseStatus::ACTIVE->value,
                    'canonical_domain' => $canonicalDomain,
                    'expires_at' => $license->expires_at?->toIso8601String(),
                    'token' => $rawToken,
                    'token_id' => $jti,
                    'token_expires_at' => $tokenExpiresAt,
                    'refreshed' => false,
                    'server_time' => $serverNow->toIso8601String(),
                ];
                return;
            }

            // Rolling refresh triggered: generate new generation JTI and signed token
            $newJti = 'tok_' . bin2hex(random_bytes(16));
            $tokenTtlSeconds = (int) config('license.token_ttl_seconds', 604800);
            $maxTokenExp = $serverNow->copy()->addSeconds($tokenTtlSeconds);
            $newTokenExp = $license->expires_at ? min($maxTokenExp, $license->expires_at) : $maxTokenExp;

            $newTokenPayload = [
                'jti' => $newJti,
                'iss' => config('license.issuer', 'license.katresnanku.com'),
                'aud' => $license->application->code,
                'sub' => $license->key_masked,
                'dom' => $canonicalDomain,
                'iat' => $serverNow->timestamp,
                'nbf' => $serverNow->timestamp,
                'exp' => $newTokenExp->timestamp,
                'lic_exp' => $license->expires_at?->timestamp,
                'customer' => [
                    'name' => $license->customer_name,
                    'email' => $license->customer_email,
                ],
            ];

            $newSignedToken = $this->tokenSigner->sign($newTokenPayload);

            // Update activation record to point to new generation
            $lockedActivation->update([
                'token_id' => $newJti,
                'token_expires_at' => $newTokenExp,
                'last_verified_at' => $serverNow,
                'ip_address' => $ipAddress,
            ]);

            LicenseLog::create([
                'application_id' => $license->application_id,
                'license_id' => $license->id,
                'event' => LicenseLogEvent::TOKEN_REFRESHED,
                'actor_type' => LicenseLogActorType::CLIENT,
                'actor_user_id' => null,
                'actor_key_id' => $apiKey->key_id,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'payload' => [
                    'domain' => $canonicalDomain,
                    'previous_token_id' => $jti,
                    'new_token_id' => $newJti,
                    'token_expires_at' => $newTokenExp->toIso8601String(),
                    'refreshed' => true,
                ],
                'created_at' => $serverNow,
            ]);

            $result = [
                'valid' => true,
                'status' => LicenseStatus::ACTIVE->value,
                'canonical_domain' => $canonicalDomain,
                'expires_at' => $license->expires_at?->toIso8601String(),
                'token' => $newSignedToken,
                'token_id' => $newJti,
                'token_expires_at' => $newTokenExp->toIso8601String(),
                'refreshed' => true,
                'server_time' => $serverNow->toIso8601String(),
            ];
        });

        if ($failure !== null) {
            $this->logVerificationFailure(
                applicationId: $apiKey->application_id,
                licenseId: $failure['license_id'],
                actorKeyId: $apiKey->key_id,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                reason: $failure['reason'],
                domain: $canonicalDomain,
                serverNow: $serverNow,
                tokenId: $failure['token_id'] ?? null
            );

            throw new LicenseVerificationException(
                $failure['message'],
                $failure['code'],
                $failure['status']
            );
        }

        return $result;
    }

    /**
     * Write a verification failure audit log record.
     */
    private function logVerificationFailure(
        int $applicationId,
        ?int $licenseId,
        string $actorKeyId,
        ?string $ipAddress,
        ?string $userAgent,
        string $reason,
        ?string $domain,
        \DateTimeInterface $serverNow,
        ?string $tokenId = null
    ): void {
        LicenseLog::create([
            'application_id' => $applicationId,
            'license_id' => $licenseId,
            'event' => LicenseLogEvent::VERIFICATION_FAILED,
            'actor_type' => LicenseLogActorType::CLIENT,
            'actor_user_id' => null,
            'actor_key_id' => $actorKeyId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'payload' => [
                'reason' => $reason,
                'domain' => $domain,
                'token_id' => $tokenId,
            ],
            'created_at' => $serverNow,
        ]);
    }
}

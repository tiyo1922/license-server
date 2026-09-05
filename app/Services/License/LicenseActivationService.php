<?php

namespace App\Services\License;

use App\Enums\LicenseLogActorType;
use App\Enums\LicenseLogEvent;
use App\Enums\LicenseStatus;
use App\Exceptions\DomainCanonicalizationException;
use App\Exceptions\LicenseActivationException;
use App\Models\Activation;
use App\Models\ApplicationApiKey;
use App\Models\License;
use App\Models\LicenseLog;
use App\Services\License\Token\TokenSignerInterface;
use Illuminate\Support\Facades\DB;

class LicenseActivationService
{
    public function __construct(
        private DomainCanonicalizer $domainCanonicalizer,
        private LicenseKeyGenerator $licenseKeyGenerator,
        private TokenSignerInterface $tokenSigner
    ) {}

    /**
     * Activate a license key for a client domain.
     *
     * @param ApplicationApiKey $apiKey
     * @param string $rawLicenseKey
     * @param string $rawDomain
     * @param string|null $ipAddress
     * @param string|null $userAgent
     * @return array<string, mixed>
     *
     * @throws LicenseActivationException
     */
    public function activate(
        ApplicationApiKey $apiKey,
        string $rawLicenseKey,
        string $rawDomain,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        $serverNow = now('UTC');

        // 1. Canonicalize domain
        try {
            $canonicalDomain = $this->domainCanonicalizer->canonicalize($rawDomain);
        } catch (DomainCanonicalizationException $e) {
            $this->logActivationFailure(
                applicationId: $apiKey->application_id,
                licenseId: null,
                actorKeyId: $apiKey->key_id,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                reason: 'INVALID_DOMAIN',
                domain: null,
                serverNow: $serverNow
            );

            throw new LicenseActivationException('The provided domain is invalid or malformed.', 'INVALID_DOMAIN', 422);
        }

        // 2. Parse and validate license key format and CRC32b checksum
        $parsed = $this->licenseKeyGenerator->parse($rawLicenseKey);
        if ($parsed === null) {
            $this->logActivationFailure(
                applicationId: $apiKey->application_id,
                licenseId: null,
                actorKeyId: $apiKey->key_id,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                reason: 'INVALID_LICENSE_KEY',
                domain: $canonicalDomain,
                serverNow: $serverNow
            );

            throw new LicenseActivationException('The provided license key format or checksum is invalid.', 'INVALID_LICENSE_KEY', 422);
        }

        $keyHash = hash('sha256', $parsed['canonical_key']);

        // 3. Concurrency boundary & Transaction
        $failure = null;
        $result = null;

        DB::transaction(function () use (
            $apiKey, $keyHash, $canonicalDomain, $ipAddress, $userAgent, $serverNow,
            &$failure, &$result
        ) {
            $lockedLicense = License::with(['application', 'activation'])
                ->where('key_hash', $keyHash)
                ->lockForUpdate()
                ->first();

            // License existence
            if ($lockedLicense === null) {
                $failure = [
                    'license_id' => null,
                    'reason' => 'LICENSE_NOT_FOUND',
                    'message' => 'The provided license key is invalid.',
                    'code' => 'INVALID_LICENSE',
                    'status' => 422,
                    'payload' => [
                        'reason' => 'LICENSE_NOT_FOUND',
                        'domain' => $canonicalDomain,
                    ],
                ];
                return;
            }

            // Application boundary enforcement
            if ($lockedLicense->application_id !== $apiKey->application_id) {
                $failure = [
                    'license_id' => $lockedLicense->id,
                    'reason' => 'CROSS_APPLICATION_MISMATCH',
                    'message' => 'The provided license is invalid for this application.',
                    'code' => 'INVALID_LICENSE',
                    'status' => 422,
                    'payload' => [
                        'reason' => 'CROSS_APPLICATION_MISMATCH',
                        'domain' => $canonicalDomain,
                    ],
                ];
                return;
            }

            $authoritativeStatus = $lockedLicense->getAuthoritativeStatus($serverNow);

            // Terminal REVOKED status
            if ($authoritativeStatus === LicenseStatus::REVOKED) {
                $failure = [
                    'license_id' => $lockedLicense->id,
                    'reason' => 'LICENSE_REVOKED',
                    'message' => 'This license has been permanently revoked.',
                    'code' => 'LICENSE_REVOKED',
                    'status' => 403,
                    'payload' => [
                        'reason' => 'LICENSE_REVOKED',
                        'domain' => $canonicalDomain,
                    ],
                ];
                return;
            }

            // Authoritative EXPIRED status
            if ($authoritativeStatus === LicenseStatus::EXPIRED) {
                $failure = [
                    'license_id' => $lockedLicense->id,
                    'reason' => 'LICENSE_EXPIRED',
                    'message' => 'This license has expired. Renewal is required prior to activation.',
                    'code' => 'LICENSE_EXPIRED',
                    'status' => 403,
                    'payload' => [
                        'reason' => 'LICENSE_EXPIRED',
                        'domain' => $canonicalDomain,
                    ],
                ];
                return;
            }

            // SUSPENDED status
            if ($authoritativeStatus === LicenseStatus::SUSPENDED) {
                $failure = [
                    'license_id' => $lockedLicense->id,
                    'reason' => 'LICENSE_SUSPENDED',
                    'message' => 'This license is currently suspended.',
                    'code' => 'LICENSE_SUSPENDED',
                    'status' => 403,
                    'payload' => [
                        'reason' => 'LICENSE_SUSPENDED',
                        'domain' => $canonicalDomain,
                    ],
                ];
                return;
            }

            // ACTIVE status (Idempotent Reactivation or Domain Mismatch Rejection)
            if ($authoritativeStatus === LicenseStatus::ACTIVE) {
                if ($lockedLicense->activation && hash_equals($lockedLicense->activation->canonical_domain, $canonicalDomain)) {
                    $tokenId = 'tok_' . bin2hex(random_bytes(16));
                    $tokenTtlSeconds = config('license.token_ttl_seconds', 604800);
                    $maxTokenExp = $serverNow->copy()->addSeconds($tokenTtlSeconds);
                    $tokenExp = $lockedLicense->expires_at ? min($maxTokenExp, $lockedLicense->expires_at) : $maxTokenExp;

                    $lockedLicense->activation->update([
                        'token_id' => $tokenId,
                        'token_expires_at' => $tokenExp,
                        'last_verified_at' => $serverNow,
                        'ip_address' => $ipAddress,
                    ]);

                    $tokenPayload = [
                        'jti' => $tokenId,
                        'iss' => config('license.issuer', 'license.katresnanku.com'),
                        'aud' => $lockedLicense->application->code,
                        'sub' => $lockedLicense->key_masked,
                        'dom' => $canonicalDomain,
                        'iat' => $serverNow->timestamp,
                        'nbf' => $serverNow->timestamp,
                        'exp' => $tokenExp->timestamp,
                        'lic_exp' => $lockedLicense->expires_at?->timestamp,
                        'customer' => [
                            'name' => $lockedLicense->customer_name,
                            'email' => $lockedLicense->customer_email,
                        ],
                    ];

                    $signedToken = $this->tokenSigner->sign($tokenPayload);

                    LicenseLog::create([
                        'application_id' => $lockedLicense->application_id,
                        'license_id' => $lockedLicense->id,
                        'event' => LicenseLogEvent::ACTIVATION_SUCCESS,
                        'actor_type' => LicenseLogActorType::CLIENT,
                        'actor_user_id' => null,
                        'actor_key_id' => $apiKey->key_id,
                        'ip_address' => $ipAddress,
                        'user_agent' => $userAgent,
                        'payload' => [
                            'domain' => $canonicalDomain,
                            'token_id' => $tokenId,
                            'token_expires_at' => $tokenExp->toIso8601String(),
                            'reactivation' => true,
                        ],
                        'created_at' => $serverNow,
                    ]);

                    $result = [
                        'activated' => true,
                        'license_key_masked' => $lockedLicense->key_masked,
                        'canonical_domain' => $canonicalDomain,
                        'status' => LicenseStatus::ACTIVE->value,
                        'expires_at' => $lockedLicense->expires_at?->toIso8601String(),
                        'token' => $signedToken,
                        'token_id' => $tokenId,
                        'token_expires_at' => $tokenExp->toIso8601String(),
                        'public_key' => config('license.ed25519_public_key') ?: (method_exists($this->tokenSigner, 'getPublicKeyBase64') ? $this->tokenSigner->getPublicKeyBase64() : null),
                    ];
                    return;
                }

                $failure = [
                    'license_id' => $lockedLicense->id,
                    'reason' => 'DOMAIN_MISMATCH',
                    'message' => 'This license is already bound to another domain.',
                    'code' => 'LICENSE_ALREADY_BOUND',
                    'status' => 409,
                    'payload' => [
                        'reason' => 'DOMAIN_MISMATCH',
                        'attempted_domain' => $canonicalDomain,
                        'bound_domain' => $lockedLicense->activation?->canonical_domain,
                    ],
                ];
                return;
            }

            // UNUSED status (First Activation)
            if ($authoritativeStatus === LicenseStatus::UNUSED) {
                $tokenId = 'tok_' . bin2hex(random_bytes(16));
                $tokenTtlSeconds = config('license.token_ttl_seconds', 604800);
                $maxTokenExp = $serverNow->copy()->addSeconds($tokenTtlSeconds);
                $tokenExp = $lockedLicense->expires_at ? min($maxTokenExp, $lockedLicense->expires_at) : $maxTokenExp;

                $lockedLicense->status = LicenseStatus::ACTIVE;
                $lockedLicense->save();

                Activation::create([
                    'license_id' => $lockedLicense->id,
                    'canonical_domain' => $canonicalDomain,
                    'ip_address' => $ipAddress,
                    'token_id' => $tokenId,
                    'token_expires_at' => $tokenExp,
                    'activated_at' => $serverNow,
                    'last_verified_at' => $serverNow,
                ]);

                $tokenPayload = [
                    'jti' => $tokenId,
                    'iss' => config('license.issuer', 'license.katresnanku.com'),
                    'aud' => $lockedLicense->application->code,
                    'sub' => $lockedLicense->key_masked,
                    'dom' => $canonicalDomain,
                    'iat' => $serverNow->timestamp,
                    'nbf' => $serverNow->timestamp,
                    'exp' => $tokenExp->timestamp,
                    'lic_exp' => $lockedLicense->expires_at?->timestamp,
                    'customer' => [
                        'name' => $lockedLicense->customer_name,
                        'email' => $lockedLicense->customer_email,
                    ],
                ];

                $signedToken = $this->tokenSigner->sign($tokenPayload);

                LicenseLog::create([
                    'application_id' => $lockedLicense->application_id,
                    'license_id' => $lockedLicense->id,
                    'event' => LicenseLogEvent::ACTIVATION_SUCCESS,
                    'actor_type' => LicenseLogActorType::CLIENT,
                    'actor_user_id' => null,
                    'actor_key_id' => $apiKey->key_id,
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                    'payload' => [
                        'domain' => $canonicalDomain,
                        'token_id' => $tokenId,
                        'token_expires_at' => $tokenExp->toIso8601String(),
                        'first_activation' => true,
                    ],
                    'created_at' => $serverNow,
                ]);

                $result = [
                    'activated' => true,
                    'license_key_masked' => $lockedLicense->key_masked,
                    'canonical_domain' => $canonicalDomain,
                    'status' => LicenseStatus::ACTIVE->value,
                    'expires_at' => $lockedLicense->expires_at?->toIso8601String(),
                    'token' => $signedToken,
                    'token_id' => $tokenId,
                    'token_expires_at' => $tokenExp->toIso8601String(),
                    'public_key' => config('license.ed25519_public_key') ?: (method_exists($this->tokenSigner, 'getPublicKeyBase64') ? $this->tokenSigner->getPublicKeyBase64() : null),
                ];
                return;
            }

            $failure = [
                'license_id' => $lockedLicense->id,
                'reason' => 'INVALID_STATE',
                'message' => 'Cannot activate license in current state.',
                'code' => 'INVALID_STATE',
                'status' => 422,
                'payload' => [
                    'reason' => 'INVALID_STATE',
                    'domain' => $canonicalDomain,
                ],
            ];
        });

        if ($failure !== null) {
            $this->logActivationFailure(
                applicationId: $apiKey->application_id,
                licenseId: $failure['license_id'],
                actorKeyId: $apiKey->key_id,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                reason: $failure['reason'],
                domain: $canonicalDomain,
                serverNow: $serverNow,
                customPayload: $failure['payload'] ?? null
            );

            throw new LicenseActivationException(
                $failure['message'],
                $failure['code'],
                $failure['status']
            );
        }

        return $result;
    }

    /**
     * Write an activation failure audit log record.
     */
    private function logActivationFailure(
        int $applicationId,
        ?int $licenseId,
        string $actorKeyId,
        ?string $ipAddress,
        ?string $userAgent,
        string $reason,
        ?string $domain,
        \DateTimeInterface $serverNow,
        ?array $customPayload = null
    ): void {
        LicenseLog::create([
            'application_id' => $applicationId,
            'license_id' => $licenseId,
            'event' => LicenseLogEvent::ACTIVATION_FAILED,
            'actor_type' => LicenseLogActorType::CLIENT,
            'actor_user_id' => null,
            'actor_key_id' => $actorKeyId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'payload' => $customPayload ?? [
                'reason' => $reason,
                'domain' => $domain,
            ],
            'created_at' => $serverNow,
        ]);
    }
}

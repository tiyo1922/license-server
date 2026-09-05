<?php

namespace App\Services\License\Client;

use App\Exceptions\DomainCanonicalizationException;
use App\Services\License\DomainCanonicalizer;
use App\Services\License\Token\TokenVerifierInterface;
use RuntimeException;

class ClientLicenseVerifier
{
    public function __construct(
        private TokenVerifierInterface $tokenVerifier,
        private DomainCanonicalizer $domainCanonicalizer,
        private string $expectedIssuer,
        private string $expectedApplicationCode,
        private int $clockSkewSeconds = 60
    ) {}

    /**
     * Perform reference offline verification of a license token against local environment expectations.
     *
     * @param string $token Full compact signed token
     * @param string $expectedDomain Expected client host or URL
     * @param int|null $currentTime UTC epoch timestamp (defaults to system time())
     * @return VerificationResult
     */
    public function verify(string $token, string $expectedDomain, ?int $currentTime = null): VerificationResult
    {
        $now = $currentTime ?? time();

        // 1. Canonicalize expected domain
        try {
            $canonicalDomain = $this->domainCanonicalizer->canonicalize($expectedDomain);
        } catch (DomainCanonicalizationException $e) {
            return VerificationResult::invalid(
                VerificationStatusCode::INVALID_DOMAIN,
                'The local domain identity is invalid or malformed.',
                null,
                null,
                $now
            );
        }

        // 2. Safely parse unverified header/payload for metadata inspection in case of crypto failure
        $unverifiedHeader = null;
        $unverifiedPayload = null;
        try {
            $unverified = $this->tokenVerifier->parseUnverified($token);
            $unverifiedHeader = $unverified['header'];
            $unverifiedPayload = $unverified['payload'];
        } catch (RuntimeException $e) {
            return VerificationResult::invalid(
                VerificationStatusCode::INVALID_STRUCTURE,
                'Token structure is malformed. Expected 3 dot-separated Base64URL segments.',
                null,
                null,
                $now
            );
        }

        // 3. Cryptographic signature and envelope verification
        try {
            $verifiedPayload = $this->tokenVerifier->verify($token);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();

            if (str_contains($msg, 'header algorithm or type')) {
                return VerificationResult::invalid(
                    VerificationStatusCode::INVALID_HEADER,
                    'Unsupported token header algorithm or type.',
                    $unverifiedHeader,
                    $unverifiedPayload,
                    $now
                );
            }

            if (str_contains($msg, 'key identifier')) {
                return VerificationResult::invalid(
                    VerificationStatusCode::UNKNOWN_KEY,
                    'The token key identifier (kid) is not in the trusted key registry.',
                    $unverifiedHeader,
                    $unverifiedPayload,
                    $now
                );
            }

            if (str_contains($msg, 'Invalid token signature')) {
                return VerificationResult::invalid(
                    VerificationStatusCode::INVALID_SIGNATURE,
                    'Token cryptographic signature is invalid or payload has been tampered with.',
                    $unverifiedHeader,
                    $unverifiedPayload,
                    $now
                );
            }

            return VerificationResult::invalid(
                VerificationStatusCode::INVALID_PAYLOAD,
                'Token verification failed: ' . $msg,
                $unverifiedHeader,
                $unverifiedPayload,
                $now
            );
        }

        // 4. Validate presence of required claims
        $requiredClaims = ['jti', 'iss', 'aud', 'sub', 'dom', 'iat', 'nbf', 'exp'];
        foreach ($requiredClaims as $claim) {
            if (! array_key_exists($claim, $verifiedPayload)) {
                return VerificationResult::invalid(
                    VerificationStatusCode::INVALID_PAYLOAD,
                    "Token payload is missing required claim [{$claim}].",
                    $unverifiedHeader,
                    $verifiedPayload,
                    $now
                );
            }
        }

        // 5. Issuer claim validation
        if (! hash_equals($this->expectedIssuer, (string) $verifiedPayload['iss'])) {
            return VerificationResult::invalid(
                VerificationStatusCode::INVALID_ISSUER,
                'Token issuer does not match expected authority.',
                $unverifiedHeader,
                $verifiedPayload,
                $now
            );
        }

        // 6. Audience claim validation
        if (! hash_equals($this->expectedApplicationCode, (string) $verifiedPayload['aud'])) {
            return VerificationResult::invalid(
                VerificationStatusCode::INVALID_AUDIENCE,
                'Token audience does not match this application.',
                $unverifiedHeader,
                $verifiedPayload,
                $now
            );
        }

        // 7. Domain claim validation
        if (! hash_equals($canonicalDomain, (string) $verifiedPayload['dom'])) {
            return VerificationResult::invalid(
                VerificationStatusCode::INVALID_DOMAIN,
                'Token domain binding does not match current canonical domain.',
                $unverifiedHeader,
                $verifiedPayload,
                $now
            );
        }

        // 8. Temporal validity checks (with clock skew allowance)
        $nbf = (int) $verifiedPayload['nbf'];
        $exp = (int) $verifiedPayload['exp'];

        if ($now + $this->clockSkewSeconds < $nbf) {
            return VerificationResult::invalid(
                VerificationStatusCode::NOT_YET_VALID,
                'Token is not yet active according to local clock.',
                $unverifiedHeader,
                $verifiedPayload,
                $now
            );
        }

        // 9. License expiration consistency validation
        if (array_key_exists('lic_exp', $verifiedPayload) && $verifiedPayload['lic_exp'] !== null) {
            $licExp = (int) $verifiedPayload['lic_exp'];

            if ($exp > $licExp) {
                return VerificationResult::invalid(
                    VerificationStatusCode::EXPIRY_INCONSISTENCY,
                    'Token expiration exceeds authoritative license expiration timestamp.',
                    $unverifiedHeader,
                    $verifiedPayload,
                    $now
                );
            }

            if ($now - $this->clockSkewSeconds >= $licExp) {
                return VerificationResult::invalid(
                    VerificationStatusCode::LICENSE_EXPIRED,
                    'Underlying license has expired.',
                    $unverifiedHeader,
                    $verifiedPayload,
                    $now
                );
            }
        }

        if ($now - $this->clockSkewSeconds >= $exp) {
            return VerificationResult::invalid(
                VerificationStatusCode::EXPIRED,
                'Token has expired.',
                $unverifiedHeader,
                $verifiedPayload,
                $now
            );
        }

        return VerificationResult::valid(
            payload: $verifiedPayload,
            header: $unverifiedHeader ?? [],
            evaluatedAt: $now
        );
    }
}

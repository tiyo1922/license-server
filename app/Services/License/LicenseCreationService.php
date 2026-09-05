<?php

namespace App\Services\License;

use App\Enums\LicenseLogActorType;
use App\Enums\LicenseLogEvent;
use App\Enums\LicenseStatus;
use App\Exceptions\LicenseGenerationException;
use App\Models\Application;
use App\Models\License;
use App\Models\LicenseLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class LicenseCreationService
{
    public const MAX_COLLISION_RETRIES = 3;

    public function __construct(
        protected LicenseKeyGenerator $generator
    ) {}

    /**
     * Create a new license and its corresponding audit log entry atomically.
     *
     * @param Application $application
     * @param array{
     *     customer_name?: ?string,
     *     customer_email?: ?string,
     *     notes?: ?string,
     *     expires_at?: ?string
     * } $data
     * @param int|null $actorUserId
     * @return array{license: License, plaintextKey: string}
     *
     * @throws LicenseGenerationException|InvalidArgumentException|Throwable
     */
    public function createLicense(Application $application, array $data = [], ?int $actorUserId = null): array
    {
        if (! $application->is_active) {
            throw new InvalidArgumentException("Cannot create license for inactive application [{$application->code}].");
        }

        $actorUserId = $actorUserId ?? Auth::id();

        for ($attempt = 1; $attempt <= self::MAX_COLLISION_RETRIES; $attempt++) {
            // 1. Generate new cryptographically random key material
            $generatedKey = $this->generator->generate($application->code);

            try {
                // 2. Atomically insert license and audit log in one transaction
                return DB::transaction(function () use ($application, $data, $generatedKey, $actorUserId) {
                    $license = License::create([
                        'application_id' => $application->id,
                        'key_hash' => $generatedKey->keyHash,
                        'key_masked' => $generatedKey->keyMasked,
                        'status' => LicenseStatus::UNUSED,
                        'customer_name' => isset($data['customer_name']) ? trim($data['customer_name']) : null,
                        'customer_email' => isset($data['customer_email']) && ! empty($data['customer_email'])
                            ? strtolower(trim($data['customer_email']))
                            : null,
                        'notes' => isset($data['notes']) ? trim($data['notes']) : null,
                        'expires_at' => $data['expires_at'] ?? null,
                    ]);

                    LicenseLog::create([
                        'application_id' => $application->id,
                        'license_id' => $license->id,
                        'event' => LicenseLogEvent::LICENSE_CREATED,
                        'actor_type' => LicenseLogActorType::ADMIN,
                        'actor_user_id' => $actorUserId,
                        'actor_key_id' => null,
                        'ip_address' => request()->ip(),
                        'user_agent' => request()->userAgent(),
                        'payload' => [
                            'key_masked' => $generatedKey->keyMasked,
                            'application_code' => $application->code,
                            'customer_name' => $license->customer_name,
                            'customer_email' => $license->customer_email,
                            'is_lifetime' => is_null($license->expires_at),
                            'expires_at' => $license->expires_at?->toIso8601String(),
                        ],
                    ]);

                    return [
                        'license' => $license,
                        'plaintextKey' => $generatedKey->plaintextKey,
                    ];
                });
            } catch (QueryException $e) {
                // Check if exception is strictly a UNIQUE collision on licenses.key_hash
                if ($this->isKeyHashUniqueViolation($e)) {
                    // Collision occurred: discard key and retry loop with new key
                    continue;
                }

                // Unrelated database failure: rethrow immediately without retrying
                throw $e;
            }
        }

        throw new LicenseGenerationException('Unable to generate a unique license key after ' . self::MAX_COLLISION_RETRIES . ' attempts.');
    }

    /**
     * Determine if a QueryException represents a UNIQUE constraint violation specifically on licenses.key_hash.
     *
     * @param QueryException $e
     * @return bool
     */
    public function isKeyHashUniqueViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        // SQLite: "UNIQUE constraint failed: licenses.key_hash"
        if (str_contains($message, 'UNIQUE constraint failed: licenses.key_hash')) {
            return true;
        }

        // MySQL / MariaDB: Error 1062 / Duplicate entry for key 'licenses.key_hash' or 'licenses_key_hash_unique'
        if (str_contains($message, 'Duplicate entry') && (str_contains($message, 'key_hash') || str_contains($message, 'licenses_key_hash_unique'))) {
            return true;
        }

        // PostgreSQL: SQLSTATE 23505 and unique_violation on key_hash
        if (str_contains($message, '23505') && (str_contains($message, 'key_hash') || str_contains($message, 'licenses_key_hash_unique'))) {
            return true;
        }

        return false;
    }
}

<?php

namespace App\Services\License;

use App\Enums\ApplicationApiKeyStatus;
use App\Enums\LicenseLogActorType;
use App\Enums\LicenseLogEvent;
use App\Models\Application;
use App\Models\ApplicationApiKey;
use App\Models\LicenseLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ApiKeyManagementService
{
    public const MAX_COLLISION_RETRIES = 3;

    /**
     * Create a new application API key with a single-reveal plaintext secret.
     *
     * @param Application $application
     * @param array{
     *     name: string,
     *     expires_at?: ?string
     * } $data
     * @param int|null $actorUserId
     * @return array{apiKey: ApplicationApiKey, plaintextSecret: string}
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    public function createApiKey(Application $application, array $data, ?int $actorUserId = null): array
    {
        if (empty($data['name']) || ! is_string($data['name'])) {
            throw new InvalidArgumentException('API key name is required.');
        }

        $name = trim($data['name']);
        if (strlen($name) < 2 || strlen($name) > 100) {
            throw new InvalidArgumentException('API key name must be between 2 and 100 characters.');
        }

        $expiresAt = null;
        if (! empty($data['expires_at'])) {
            $parsedExpiresAt = Carbon::parse($data['expires_at'], 'UTC');
            if (now('UTC')->gte($parsedExpiresAt)) {
                throw new InvalidArgumentException('API key expiration date must be in the future.');
            }
            $expiresAt = $parsedExpiresAt;
        }

        $actorUserId = $actorUserId ?? Auth::id();

        for ($attempt = 1; $attempt <= self::MAX_COLLISION_RETRIES; $attempt++) {
            // Generate high-entropy CSPRNG credentials with standard prefixes
            $keyId = 'ak_' . Str::random(24);
            $secret = 'sec_' . Str::random(48);
            $keyHash = hash('sha256', $secret);

            // Check collision
            if (ApplicationApiKey::where('key_id', $keyId)->orWhere('key_hash', $keyHash)->exists()) {
                continue;
            }

            return DB::transaction(function () use ($application, $keyId, $keyHash, $name, $expiresAt, $secret, $actorUserId) {
                $apiKey = ApplicationApiKey::create([
                    'application_id' => $application->id,
                    'key_id' => $keyId,
                    'key_hash' => $keyHash,
                    'name' => $name,
                    'status' => ApplicationApiKeyStatus::ACTIVE,
                    'expires_at' => $expiresAt,
                ]);

                LicenseLog::create([
                    'application_id' => $application->id,
                    'license_id' => null,
                    'event' => LicenseLogEvent::API_KEY_CREATED,
                    'actor_type' => LicenseLogActorType::ADMIN,
                    'actor_user_id' => $actorUserId,
                    'actor_key_id' => $keyId,
                    'ip_address' => request()?->ip(),
                    'user_agent' => request()?->userAgent(),
                    'payload' => [
                        'key_id' => $keyId,
                        'name' => $name,
                        'expires_at' => $apiKey->expires_at?->toIso8601String(),
                    ],
                    'created_at' => now('UTC'),
                ]);

                return [
                    'apiKey' => $apiKey,
                    'plaintextSecret' => $secret,
                ];
            });
        }

        throw new RuntimeException('Failed to generate unique API key credentials after ' . self::MAX_COLLISION_RETRIES . ' attempts.');
    }

    /**
     * Terminally revoke an application API key.
     *
     * @param Application $application
     * @param ApplicationApiKey $apiKey
     * @param string|null $reason
     * @param int|null $actorUserId
     * @return ApplicationApiKey
     *
     * @throws InvalidArgumentException
     */
    public function revokeApiKey(
        Application $application,
        ApplicationApiKey $apiKey,
        ?string $reason = null,
        ?int $actorUserId = null
    ): ApplicationApiKey {
        if ($apiKey->application_id !== $application->id) {
            throw new InvalidArgumentException("API key [{$apiKey->key_id}] does not belong to application [{$application->code}].");
        }

        if ($apiKey->status === ApplicationApiKeyStatus::REVOKED) {
            return $apiKey;
        }

        $actorUserId = $actorUserId ?? Auth::id();

        return DB::transaction(function () use ($application, $apiKey, $reason, $actorUserId) {
            $apiKey->status = ApplicationApiKeyStatus::REVOKED;
            $apiKey->save();

            LicenseLog::create([
                'application_id' => $application->id,
                'license_id' => null,
                'event' => LicenseLogEvent::API_KEY_REVOKED,
                'actor_type' => LicenseLogActorType::ADMIN,
                'actor_user_id' => $actorUserId,
                'actor_key_id' => $apiKey->key_id,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'payload' => [
                    'key_id' => $apiKey->key_id,
                    'name' => $apiKey->name,
                    'reason' => $reason ? trim($reason) : null,
                ],
                'created_at' => now('UTC'),
            ]);

            return $apiKey;
        });
    }
}

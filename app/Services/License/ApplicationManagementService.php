<?php

namespace App\Services\License;

use App\Enums\LicenseLogActorType;
use App\Enums\LicenseLogEvent;
use App\Models\Application;
use App\Models\LicenseLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ApplicationManagementService
{
    /**
     * Create a new application and record an audit log event atomically.
     *
     * @param array{
     *     code: string,
     *     name: string,
     *     description?: ?string,
     *     is_active?: bool
     * } $data
     * @param int|null $actorUserId
     * @return Application
     *
     * @throws InvalidArgumentException
     */
    public function createApplication(array $data, ?int $actorUserId = null): Application
    {
        if (empty($data['code']) || ! is_string($data['code'])) {
            throw new InvalidArgumentException('Application code is required.');
        }

        $code = strtoupper(trim($data['code']));

        if (strlen($code) < 2 || strlen($code) > 50) {
            throw new InvalidArgumentException('Application code must be between 2 and 50 characters.');
        }

        if (! preg_match('/^[A-Z0-9_\-]+$/', $code)) {
            throw new InvalidArgumentException('Application code may only contain uppercase letters, numbers, hyphens, and underscores.');
        }

        if (empty($data['name']) || ! is_string($data['name'])) {
            throw new InvalidArgumentException('Application name is required.');
        }

        $name = trim($data['name']);
        if (strlen($name) < 2 || strlen($name) > 100) {
            throw new InvalidArgumentException('Application name must be between 2 and 100 characters.');
        }

        $description = isset($data['description']) && is_string($data['description']) ? trim($data['description']) : null;
        if ($description !== null && strlen($description) > 255) {
            throw new InvalidArgumentException('Application description must not exceed 255 characters.');
        }

        $isActive = isset($data['is_active']) ? (bool) $data['is_active'] : true;
        $actorUserId = $actorUserId ?? Auth::id();

        return DB::transaction(function () use ($code, $name, $description, $isActive, $actorUserId) {
            if (Application::where('code', $code)->exists()) {
                throw new InvalidArgumentException("Application code [{$code}] is already registered.");
            }

            $application = Application::create([
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'is_active' => $isActive,
            ]);

            LicenseLog::create([
                'application_id' => $application->id,
                'license_id' => null,
                'event' => LicenseLogEvent::APPLICATION_CREATED,
                'actor_type' => LicenseLogActorType::ADMIN,
                'actor_user_id' => $actorUserId,
                'actor_key_id' => null,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'payload' => [
                    'code' => $application->code,
                    'name' => $application->name,
                    'is_active' => $application->is_active,
                ],
                'created_at' => now('UTC'),
            ]);

            return $application;
        });
    }

    /**
     * Update application metadata (name, description) atomically.
     * Note: Application code is strictly immutable and cannot be modified.
     *
     * @param Application $application
     * @param array{
     *     code?: string,
     *     name?: string,
     *     description?: ?string
     * } $data
     * @param int|null $actorUserId
     * @return Application
     *
     * @throws InvalidArgumentException
     */
    public function updateApplication(Application $application, array $data, ?int $actorUserId = null): Application
    {
        // Enforce strict code immutability
        if (isset($data['code'])) {
            $providedCode = strtoupper(trim((string) $data['code']));
            if ($providedCode !== $application->code) {
                throw new InvalidArgumentException('Application code is strictly immutable and cannot be modified after creation.');
            }
        }

        $name = isset($data['name']) ? trim((string) $data['name']) : $application->name;
        if (strlen($name) < 2 || strlen($name) > 100) {
            throw new InvalidArgumentException('Application name must be between 2 and 100 characters.');
        }

        $description = array_key_exists('description', $data)
            ? (is_string($data['description']) ? trim($data['description']) : null)
            : $application->description;

        if ($description !== null && strlen($description) > 255) {
            throw new InvalidArgumentException('Application description must not exceed 255 characters.');
        }

        $actorUserId = $actorUserId ?? Auth::id();

        return DB::transaction(function () use ($application, $name, $description, $actorUserId) {
            $application->name = $name;
            $application->description = $description;
            $application->save();

            LicenseLog::create([
                'application_id' => $application->id,
                'license_id' => null,
                'event' => LicenseLogEvent::APPLICATION_UPDATED,
                'actor_type' => LicenseLogActorType::ADMIN,
                'actor_user_id' => $actorUserId,
                'actor_key_id' => null,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'payload' => [
                    'name' => $application->name,
                    'description' => $application->description,
                ],
                'created_at' => now('UTC'),
            ]);

            return $application;
        });
    }

    /**
     * Toggle or explicitly set an application's active status.
     *
     * @param Application $application
     * @param bool|null $newStatus
     * @param string|null $reason
     * @param int|null $actorUserId
     * @return Application
     */
    public function toggleActive(
        Application $application,
        ?bool $newStatus = null,
        ?string $reason = null,
        ?int $actorUserId = null
    ): Application {
        $actorUserId = $actorUserId ?? Auth::id();

        return DB::transaction(function () use ($application, $newStatus, $reason, $actorUserId) {
            $previousStatus = $application->is_active;
            $targetStatus = $newStatus !== null ? $newStatus : ! $previousStatus;

            $application->is_active = $targetStatus;
            $application->save();

            LicenseLog::create([
                'application_id' => $application->id,
                'license_id' => null,
                'event' => LicenseLogEvent::APPLICATION_TOGGLED,
                'actor_type' => LicenseLogActorType::ADMIN,
                'actor_user_id' => $actorUserId,
                'actor_key_id' => null,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'payload' => [
                    'is_active' => $application->is_active,
                    'previous_is_active' => $previousStatus,
                    'reason' => $reason ? trim($reason) : null,
                ],
                'created_at' => now('UTC'),
            ]);

            return $application;
        });
    }

    /**
     * Enable an application.
     */
    public function enableApplication(Application $application, ?string $reason = null, ?int $actorUserId = null): Application
    {
        return $this->toggleActive($application, true, $reason, $actorUserId);
    }

    /**
     * Disable an application.
     */
    public function disableApplication(Application $application, ?string $reason = null, ?int $actorUserId = null): Application
    {
        return $this->toggleActive($application, false, $reason, $actorUserId);
    }
}

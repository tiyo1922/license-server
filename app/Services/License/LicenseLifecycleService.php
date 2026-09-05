<?php

namespace App\Services\License;

use App\Enums\LicenseLogActorType;
use App\Enums\LicenseLogEvent;
use App\Enums\LicenseStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\License;
use App\Models\LicenseLog;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LicenseLifecycleService
{
    /**
     * Suspend an active license.
     *
     * @throws InvalidStateTransitionException
     */
    public function suspend(License $license, ?string $reason = null, ?int $actorUserId = null): License
    {
        return DB::transaction(function () use ($license, $reason, $actorUserId) {
            $lockedLicense = License::where('id', $license->id)->lockForUpdate()->firstOrFail();
            $serverNow = now('UTC');
            $authoritativeStatus = $lockedLicense->getAuthoritativeStatus($serverNow);

            if ($authoritativeStatus === LicenseStatus::SUSPENDED) {
                throw new InvalidStateTransitionException('License is already suspended.');
            }

            if ($authoritativeStatus === LicenseStatus::EXPIRED) {
                throw new InvalidStateTransitionException('Cannot suspend an expired license.');
            }

            if ($authoritativeStatus === LicenseStatus::REVOKED) {
                throw new InvalidStateTransitionException('Cannot suspend a revoked license.');
            }

            if ($authoritativeStatus === LicenseStatus::UNUSED) {
                throw new InvalidStateTransitionException('Cannot suspend an unactivated license.');
            }

            if ($authoritativeStatus !== LicenseStatus::ACTIVE) {
                throw new InvalidStateTransitionException("Cannot suspend license in [{$authoritativeStatus->value}] status.");
            }

            $lockedLicense->status = LicenseStatus::SUSPENDED;
            $lockedLicense->save();

            LicenseLog::create([
                'application_id' => $lockedLicense->application_id,
                'license_id' => $lockedLicense->id,
                'event' => LicenseLogEvent::LICENSE_SUSPENDED,
                'actor_type' => LicenseLogActorType::ADMIN,
                'actor_user_id' => $actorUserId ?? Auth::id(),
                'actor_key_id' => null,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'payload' => [
                    'reason' => $reason ? trim($reason) : null,
                    'previous_status' => LicenseStatus::ACTIVE->value,
                    'domain' => $lockedLicense->activation?->canonical_domain,
                ],
                'created_at' => $serverNow,
            ]);

            return $lockedLicense;
        });
    }

    /**
     * Unsuspend a suspended license back to active status.
     *
     * @throws InvalidStateTransitionException
     */
    public function unsuspend(License $license, ?string $reason = null, ?int $actorUserId = null): License
    {
        return DB::transaction(function () use ($license, $reason, $actorUserId) {
            $lockedLicense = License::where('id', $license->id)->lockForUpdate()->firstOrFail();
            $serverNow = now('UTC');
            $authoritativeStatus = $lockedLicense->getAuthoritativeStatus($serverNow);

            if ($authoritativeStatus === LicenseStatus::EXPIRED) {
                throw new InvalidStateTransitionException('Cannot unsuspend an expired license. Renewal is required.');
            }

            if ($authoritativeStatus === LicenseStatus::ACTIVE) {
                throw new InvalidStateTransitionException('License is already active.');
            }

            if ($authoritativeStatus === LicenseStatus::REVOKED) {
                throw new InvalidStateTransitionException('Cannot unsuspend a revoked license.');
            }

            if ($authoritativeStatus === LicenseStatus::UNUSED) {
                throw new InvalidStateTransitionException('Cannot unsuspend an unactivated license.');
            }

            if ($authoritativeStatus !== LicenseStatus::SUSPENDED) {
                throw new InvalidStateTransitionException("Cannot unsuspend license in [{$authoritativeStatus->value}] status.");
            }

            $lockedLicense->status = LicenseStatus::ACTIVE;
            $lockedLicense->save();

            LicenseLog::create([
                'application_id' => $lockedLicense->application_id,
                'license_id' => $lockedLicense->id,
                'event' => LicenseLogEvent::LICENSE_UNSUSPENDED,
                'actor_type' => LicenseLogActorType::ADMIN,
                'actor_user_id' => $actorUserId ?? Auth::id(),
                'actor_key_id' => null,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'payload' => [
                    'reason' => $reason ? trim($reason) : null,
                    'previous_status' => LicenseStatus::SUSPENDED->value,
                    'domain' => $lockedLicense->activation?->canonical_domain,
                ],
                'created_at' => $serverNow,
            ]);

            return $lockedLicense;
        });
    }

    /**
     * Permanently revoke a license (terminal action).
     *
     * @throws InvalidStateTransitionException
     */
    public function revoke(License $license, ?string $reason = null, ?int $actorUserId = null): License
    {
        return DB::transaction(function () use ($license, $reason, $actorUserId) {
            $lockedLicense = License::where('id', $license->id)->lockForUpdate()->firstOrFail();
            $serverNow = now('UTC');
            $authoritativeStatus = $lockedLicense->getAuthoritativeStatus($serverNow);
            $originStatus = $lockedLicense->getOriginStatusBeforeExpiration();

            if ($authoritativeStatus === LicenseStatus::REVOKED || $lockedLicense->status === LicenseStatus::REVOKED) {
                throw new InvalidStateTransitionException('License is already permanently revoked.');
            }

            if ($originStatus === LicenseStatus::UNUSED) {
                throw new InvalidStateTransitionException('Cannot revoke an unactivated license.');
            }

            // Dissolve activation record if present
            $domain = $lockedLicense->activation?->canonical_domain;
            $lockedLicense->activation()?->delete();

            $previousStatus = $lockedLicense->status->value;
            $lockedLicense->status = LicenseStatus::REVOKED;
            $lockedLicense->save();

            LicenseLog::create([
                'application_id' => $lockedLicense->application_id,
                'license_id' => $lockedLicense->id,
                'event' => LicenseLogEvent::LICENSE_REVOKED,
                'actor_type' => LicenseLogActorType::ADMIN,
                'actor_user_id' => $actorUserId ?? Auth::id(),
                'actor_key_id' => null,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'payload' => [
                    'reason' => $reason ? trim($reason) : null,
                    'previous_status' => $previousStatus,
                    'origin_status' => $originStatus->value,
                    'dissolved_domain' => $domain,
                ],
                'created_at' => $serverNow,
            ]);

            return $lockedLicense;
        });
    }

    /**
     * Reset domain activation binding, returning license to UNUSED status.
     *
     * @throws InvalidStateTransitionException
     */
    public function resetBinding(License $license, ?string $reason = null, ?int $actorUserId = null): License
    {
        return DB::transaction(function () use ($license, $reason, $actorUserId) {
            $lockedLicense = License::where('id', $license->id)->lockForUpdate()->firstOrFail();
            $serverNow = now('UTC');
            $authoritativeStatus = $lockedLicense->getAuthoritativeStatus($serverNow);
            $originStatus = $lockedLicense->getOriginStatusBeforeExpiration();

            if ($authoritativeStatus === LicenseStatus::REVOKED || $lockedLicense->status === LicenseStatus::REVOKED) {
                throw new InvalidStateTransitionException('Cannot reset binding on a revoked license.');
            }

            if ($originStatus === LicenseStatus::UNUSED) {
                throw new InvalidStateTransitionException('Cannot reset binding on an unactivated license.');
            }

            if ($originStatus === LicenseStatus::ACTIVE) {
                throw new InvalidStateTransitionException('Cannot reset binding directly from ACTIVE status. The license must be suspended first.');
            }

            if ($originStatus !== LicenseStatus::SUSPENDED) {
                throw new InvalidStateTransitionException("Cannot reset binding for license in [{$originStatus->value}] status.");
            }

            $domain = $lockedLicense->activation?->canonical_domain;
            $lockedLicense->activation()?->delete();

            $lockedLicense->status = LicenseStatus::UNUSED;
            $lockedLicense->save();

            LicenseLog::create([
                'application_id' => $lockedLicense->application_id,
                'license_id' => $lockedLicense->id,
                'event' => LicenseLogEvent::BINDING_RESET,
                'actor_type' => LicenseLogActorType::ADMIN,
                'actor_user_id' => $actorUserId ?? Auth::id(),
                'actor_key_id' => null,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'payload' => [
                    'reason' => $reason ? trim($reason) : null,
                    'previous_status' => $originStatus->value,
                    'released_domain' => $domain,
                ],
                'created_at' => $serverNow,
            ]);

            return $lockedLicense;
        });
    }

    /**
     * Renew an expired or active time-bound license with a new future expiration timestamp.
     *
     * @throws InvalidStateTransitionException
     */
    public function renew(License $license, string $newExpiresAt, ?string $reason = null, ?int $actorUserId = null): License
    {
        return DB::transaction(function () use ($license, $newExpiresAt, $reason, $actorUserId) {
            $lockedLicense = License::where('id', $license->id)->lockForUpdate()->firstOrFail();
            $serverNow = now('UTC');
            $authoritativeStatus = $lockedLicense->getAuthoritativeStatus($serverNow);
            $originStatus = $lockedLicense->getOriginStatusBeforeExpiration();

            if ($authoritativeStatus === LicenseStatus::REVOKED || $lockedLicense->status === LicenseStatus::REVOKED) {
                throw new InvalidStateTransitionException('Cannot renew a permanently revoked license.');
            }

            $parsedNewExpiry = Carbon::parse($newExpiresAt, 'UTC');
            if ($parsedNewExpiry->lte($serverNow)) {
                throw new InvalidStateTransitionException('New expiration timestamp must be in the future relative to server UTC time.');
            }

            $previousExpiresAt = $lockedLicense->expires_at?->toIso8601String();
            $lockedLicense->expires_at = $parsedNewExpiry;

            // Target status resolution based on origin:
            // Expired UNUSED -> remains UNUSED (no activation created)
            // Expired ACTIVE / SUSPENDED -> ACTIVE (activation preserved)
            if ($originStatus === LicenseStatus::UNUSED) {
                $lockedLicense->status = LicenseStatus::UNUSED;
            } else {
                $lockedLicense->status = LicenseStatus::ACTIVE;
            }

            $lockedLicense->save();

            LicenseLog::create([
                'application_id' => $lockedLicense->application_id,
                'license_id' => $lockedLicense->id,
                'event' => LicenseLogEvent::LICENSE_RENEWED,
                'actor_type' => LicenseLogActorType::ADMIN,
                'actor_user_id' => $actorUserId ?? Auth::id(),
                'actor_key_id' => null,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'payload' => [
                    'reason' => $reason ? trim($reason) : null,
                    'previous_expires_at' => $previousExpiresAt,
                    'new_expires_at' => $lockedLicense->expires_at->toIso8601String(),
                    'origin_status' => $originStatus->value,
                    'target_status' => $lockedLicense->status->value,
                ],
                'created_at' => $serverNow,
            ]);

            return $lockedLicense;
        });
    }

    /**
     * Synchronize persistent status for licenses whose expires_at has passed.
     */
    public function syncExpiredLicenses(?CarbonInterface $now = null): int
    {
        $serverNow = $now ?? now('UTC');
        $count = 0;

        $eligibleLicenses = License::whereNotNull('expires_at')
            ->where('expires_at', '<=', $serverNow)
            ->whereIn('status', [LicenseStatus::UNUSED, LicenseStatus::ACTIVE, LicenseStatus::SUSPENDED])
            ->get();

        foreach ($eligibleLicenses as $license) {
            $updated = DB::transaction(function () use ($license, $serverNow) {
                $locked = License::where('id', $license->id)->lockForUpdate()->first();

                if (! $locked || ! in_array($locked->status, [LicenseStatus::UNUSED, LicenseStatus::ACTIVE, LicenseStatus::SUSPENDED])) {
                    return false;
                }

                if ($locked->expires_at && $serverNow->gte($locked->expires_at)) {
                    $prevStatus = $locked->status;
                    $locked->status = LicenseStatus::EXPIRED;
                    $locked->save();

                    LicenseLog::create([
                        'application_id' => $locked->application_id,
                        'license_id' => $locked->id,
                        'event' => LicenseLogEvent::LICENSE_EXPIRED,
                        'actor_type' => LicenseLogActorType::SYSTEM,
                        'actor_user_id' => null,
                        'actor_key_id' => null,
                        'ip_address' => null,
                        'user_agent' => 'system/scheduler',
                        'payload' => [
                            'previous_status' => $prevStatus->value ?? $prevStatus,
                            'expired_at' => $locked->expires_at->toIso8601String(),
                        ],
                        'created_at' => $serverNow,
                    ]);

                    return true;
                }

                return false;
            });

            if ($updated) {
                $count++;
            }
        }

        return $count;
    }
}

<?php

namespace App\Models;

use App\Enums\LicenseStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class License extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'application_id',
        'key_hash',
        'key_masked',
        'status',
        'customer_name',
        'customer_email',
        'notes',
        'expires_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'key_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LicenseStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Get the application that owns the license.
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * Get the current active activation for the license.
     */
    public function activation(): HasOne
    {
        return $this->hasOne(Activation::class);
    }

    /**
     * Get the audit logs associated with the license.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(LicenseLog::class);
    }

    /**
     * Check if the license is authoritatively expired based on server-side UTC time.
     */
    public function isAuthoritativelyExpired(?\Carbon\CarbonInterface $at = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        $currentTime = $at ?? now('UTC');

        return $currentTime->gte($this->expires_at);
    }

    /**
     * Resolve the authoritative lifecycle status with expiration precedence.
     */
    public function getAuthoritativeStatus(?\Carbon\CarbonInterface $at = null): LicenseStatus
    {
        // REVOKED is strictly terminal and takes precedence over timestamp expiration
        if ($this->status === LicenseStatus::REVOKED) {
            return LicenseStatus::REVOKED;
        }

        // For any time-bound license, elapsed expiration resolves to EXPIRED
        if ($this->isAuthoritativelyExpired($at)) {
            return LicenseStatus::EXPIRED;
        }

        return $this->status;
    }

    /**
     * Resolve the underlying operational origin status before expiration occurred.
     */
    public function getOriginStatusBeforeExpiration(): LicenseStatus
    {
        if ($this->status !== LicenseStatus::EXPIRED) {
            return $this->status;
        }

        // If no activation record exists, it was UNUSED
        if ($this->activation === null) {
            return LicenseStatus::UNUSED;
        }

        // If an activation record exists, check the most recent LICENSE_EXPIRED audit log
        $lastExpiredLog = $this->logs()
            ->where('event', \App\Enums\LicenseLogEvent::LICENSE_EXPIRED)
            ->latest('id')
            ->first();

        if ($lastExpiredLog && isset($lastExpiredLog->payload['previous_status'])) {
            $prev = $lastExpiredLog->payload['previous_status'];
            if ($prev === LicenseStatus::SUSPENDED->value) {
                return LicenseStatus::SUSPENDED;
            }
            if ($prev === LicenseStatus::ACTIVE->value) {
                return LicenseStatus::ACTIVE;
            }
            if ($prev === LicenseStatus::UNUSED->value) {
                return LicenseStatus::UNUSED;
            }
        }

        // Fallback if activation exists: ACTIVE
        return LicenseStatus::ACTIVE;
    }

    public function isUnused(): bool
    {
        return $this->getAuthoritativeStatus() === LicenseStatus::UNUSED;
    }

    public function isActive(): bool
    {
        return $this->getAuthoritativeStatus() === LicenseStatus::ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->getAuthoritativeStatus() === LicenseStatus::SUSPENDED;
    }

    public function isRevoked(): bool
    {
        return $this->getAuthoritativeStatus() === LicenseStatus::REVOKED;
    }

    public function isExpired(): bool
    {
        return $this->getAuthoritativeStatus() === LicenseStatus::EXPIRED;
    }

    public function isLifetime(): bool
    {
        return $this->expires_at === null;
    }

    public function isTimeBound(): bool
    {
        return $this->expires_at !== null;
    }
}


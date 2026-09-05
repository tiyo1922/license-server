<?php

namespace App\Models;

use App\Enums\LicenseLogActorType;
use App\Enums\LicenseLogEvent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseLog extends Model
{
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'application_id',
        'license_id',
        'event',
        'actor_type',
        'actor_user_id',
        'actor_key_id',
        'ip_address',
        'user_agent',
        'payload',
        'created_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => LicenseLogEvent::class,
            'actor_type' => LicenseLogActorType::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the application associated with the log.
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * Get the license associated with the log.
     */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /**
     * Get the admin user who performed the action, if applicable.
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

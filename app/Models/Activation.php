<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activation extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'license_id',
        'canonical_domain',
        'ip_address',
        'token_id',
        'token_expires_at',
        'activated_at',
        'last_verified_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    /**
     * Get the license that owns the activation.
     */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}

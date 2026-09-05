<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ed25519 Token Signing Configuration
    |--------------------------------------------------------------------------
    |
    | Asymmetric Ed25519 cryptography used to sign client license tokens.
    | Private key is kept strictly on the license server and never distributed.
    |
    */

    'ed25519_private_key' => env('ED25519_PRIVATE_KEY'),

    'ed25519_public_key' => env('ED25519_PUBLIC_KEY'),

    'ed25519_key_id' => env('ED25519_KEY_ID', 'cls-ed25519-2026-v1'),

    /*
    |--------------------------------------------------------------------------
    | Token Expiration & Issuer
    |--------------------------------------------------------------------------
    |
    | Standard token TTL in seconds (7 days = 604800s).
    |
    */

    'token_ttl_seconds' => (int) env('LICENSE_TOKEN_TTL', 604800),

    'issuer' => env('LICENSE_ISSUER', 'license.katresnanku.com'),

    /*
    |--------------------------------------------------------------------------
    | Rolling Token Refresh Threshold
    |--------------------------------------------------------------------------
    |
    | When remaining token TTL falls below this percentage of total TTL
    | (default 0.50 = 50%), a successful online verification triggers rolling refresh.
    |
    */

    'rolling_refresh_threshold_percentage' => (float) env('LICENSE_ROLLING_REFRESH_THRESHOLD', 0.50),

    /*
    |--------------------------------------------------------------------------
    | Trusted Public Keys (Key Registry)
    |--------------------------------------------------------------------------
    |
    | Map of key identifiers (kid) to trusted Ed25519 public keys (Base64).
    | Used by the verification engine and client SDK for multi-key support
    | and graceful key rotation.
    |
    */

    'trusted_public_keys' => [
        env('ED25519_KEY_ID', 'cls-ed25519-2026-v1') => env('ED25519_PUBLIC_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Offline Verification Policy
    |--------------------------------------------------------------------------
    |
    | Permissible clock skew in seconds when validating token time claims offline.
    | Standard token type identifier.
    |
    */

    'offline_clock_skew_seconds' => (int) env('LICENSE_OFFLINE_CLOCK_SKEW_SECONDS', 60),

    'token_type' => 'CLS-LIC-V1',

];

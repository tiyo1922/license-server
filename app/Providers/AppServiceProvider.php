<?php

namespace App\Providers;

use App\Database\SQLiteImmediateConnection;
use App\Services\License\Token\Ed25519TokenSigner;
use App\Services\License\Token\Ed25519TokenVerifier;
use App\Services\License\Token\StaticTrustedKeyRegistry;
use App\Services\License\Token\TokenSignerInterface;
use App\Services\License\Token\TokenVerifierInterface;
use App\Services\License\Token\TrustedKeyRegistryInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TrustedKeyRegistryInterface::class, function () {
            $initialKeys = (array) config('license.trusted_public_keys', []);
            return new StaticTrustedKeyRegistry($initialKeys);
        });

        $this->app->singleton(TokenVerifierInterface::class, function ($app) {
            return new Ed25519TokenVerifier($app->make(TrustedKeyRegistryInterface::class));
        });

        $this->app->singleton(Ed25519TokenSigner::class, function ($app) {
            $signer = new Ed25519TokenSigner();
            $registry = $app->make(TrustedKeyRegistryInterface::class);
            if (! $registry->hasKey($signer->getKeyId()) && $signer->getPublicKey() !== '') {
                $registry->registerKey($signer->getKeyId(), $signer->getPublicKey());
            }
            return $signer;
        });

        $this->app->alias(Ed25519TokenSigner::class, TokenSignerInterface::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        Connection::resolverFor('sqlite', function ($connection, $database, $prefix, $config) {
            return new SQLiteImmediateConnection($connection, $database, $prefix, $config);
        });
        RateLimiter::for('api-activation', function (Request $request) {
            $apiKeyId = $request->header('X-Api-Key-Id') ?? 'anonymous';
            $key = $request->ip() . '|' . $apiKeyId;

            return Limit::perMinute(5)->by($key)->response(function () {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'RATE_LIMIT_EXCEEDED',
                        'message' => 'Too many activation attempts. Please retry later.',
                    ],
                ], 429, ['Retry-After' => '60']);
            });
        });

        RateLimiter::for('api-verification', function (Request $request) {
            $apiKeyId = $request->header('X-Api-Key-Id') ?? 'anonymous';
            $rawDomain = (string) ($request->input('domain') ?? 'unknown');
            $key = $apiKeyId . '|' . $rawDomain;

            return Limit::perMinute(60)->by($key)->response(function () {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'RATE_LIMIT_EXCEEDED',
                        'message' => 'Too many verification attempts. Please retry later.',
                    ],
                ], 429, ['Retry-After' => '60']);
            });
        });
    }
}

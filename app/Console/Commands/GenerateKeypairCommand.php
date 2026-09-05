<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateKeypairCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-keypair {--key-id= : Optional key identifier (e.g. cls-ed25519-2026-v1)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a cryptographically secure Ed25519 keypair compatible with RFC 8032 for token signing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $keyIdOption = $this->option('key-id');
        if ($keyIdOption !== null && $keyIdOption !== '') {
            $keyId = trim((string) $keyIdOption);
            if (! preg_match('/^[a-zA-Z0-9_\-\.]+$/', $keyId)) {
                $this->error('Invalid --key-id format. Only alphanumeric characters, hyphens, underscores, and dots are permitted.');
                return self::FAILURE;
            }
        } else {
            $keyId = 'cls-ed25519-' . date('Y') . '-v1';
        }

        // Generate cryptographically secure Ed25519 keypair using libsodium CSPRNG
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $privateKeyBase64 = base64_encode($secretKey);
        $publicKeyBase64 = base64_encode($publicKey);

        $this->newLine();
        $this->line('<fg=cyan>================================================================================</>');
        $this->line('<fg=cyan;options=bold>   Ed25519 Cryptographic Signing Keypair Generated (RFC 8032)                  </>');
        $this->line('<fg=cyan>================================================================================</>');
        $this->warn(' WARNING: The private key below is highly sensitive secret material.');
        $this->warn(' Never commit it to version control, expose it to clients, or log it.');
        $this->line('<fg=cyan>================================================================================</>');
        $this->newLine();

        $this->line("<fg=yellow;options=bold>Key Identifier (kid):</> {$keyId}");
        $this->newLine();

        $this->line('<fg=yellow;options=bold>Private Key (Base64 - 64 bytes):</>');
        $this->line("<fg=green>{$privateKeyBase64}</>");
        $this->newLine();

        $this->line('<fg=yellow;options=bold>Public Key (Base64 - 32 bytes):</>');
        $this->line("<fg=white>{$publicKeyBase64}</>");
        $this->newLine();

        $this->line('<fg=cyan>--------------------------------------------------------------------------------</>');
        $this->line('<fg=cyan;options=bold> Recommended .env Configuration Snippet:                                        </>');
        $this->line('<fg=cyan>--------------------------------------------------------------------------------</>');
        $this->line("ED25519_KEY_ID={$keyId}");
        $this->line("ED25519_PRIVATE_KEY={$privateKeyBase64}");
        $this->line("ED25519_PUBLIC_KEY={$publicKeyBase64}");
        $this->line('<fg=cyan>================================================================================</>');
        $this->newLine();

        return self::SUCCESS;
    }
}

<?php

namespace Tests\Unit;

use App\Services\License\Token\Ed25519TokenSigner;
use App\Services\License\Token\Ed25519TokenVerifier;
use App\Services\License\Token\StaticTrustedKeyRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Ed25519CryptographicVerificationTest extends TestCase
{
    /**
     * Test RFC 8032 Official Test Vectors (Section 7.1).
     */
    public function test_rfc_8032_official_test_vectors(): void
    {
        // Vector 1: Empty message
        $seed1 = hex2bin('9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60');
        $pub1 = hex2bin('d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a');
        $msg1 = '';
        $expectedSig1 = hex2bin('e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b');

        $keypair1 = sodium_crypto_sign_seed_keypair($seed1);
        $secretKey1 = sodium_crypto_sign_secretkey($keypair1);
        $publicKey1 = sodium_crypto_sign_publickey($keypair1);

        $this->assertSame($pub1, $publicKey1);

        $sig1 = sodium_crypto_sign_detached($msg1, $secretKey1);
        $this->assertSame($expectedSig1, $sig1);
        $this->assertTrue(sodium_crypto_sign_verify_detached($sig1, $msg1, $pub1));

        // Vector 2: 1-byte message 0x72
        $seed2 = hex2bin('4ccd089b28ff96da9db6c346ec114e0f5b8a319f35aba624da8cf6ed4fb8a6fb');
        $pub2 = hex2bin('3d4017c3e843895a92b70aa74d1b7ebc9c982ccf2ec4968cc0cd55f12af4660c');
        $msg2 = hex2bin('72');
        $expectedSig2 = hex2bin('92a009a9f0d4cab8720e820b5f642540a2b27b5416503f8fb3762223ebdb69da085ac1e43e15996e458f3613d0f11d8c387b2eaeb4302aeeb00d291612bb0c00');

        $keypair2 = sodium_crypto_sign_seed_keypair($seed2);
        $secretKey2 = sodium_crypto_sign_secretkey($keypair2);
        $publicKey2 = sodium_crypto_sign_publickey($keypair2);

        $this->assertSame($pub2, $publicKey2);

        $sig2 = sodium_crypto_sign_detached($msg2, $secretKey2);
        $this->assertSame($expectedSig2, $sig2);
        $this->assertTrue(sodium_crypto_sign_verify_detached($sig2, $msg2, $pub2));
    }

    public function test_deterministic_token_signing_and_verification(): void
    {
        $seed = hex2bin('9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60');
        $pubKeyBase64 = base64_encode(hex2bin('d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a'));
        $privKeyBase64 = base64_encode($seed);
        $kid = 'test-ed25519-v1';

        $registry = new StaticTrustedKeyRegistry([$kid => $pubKeyBase64]);
        $verifier = new Ed25519TokenVerifier($registry);
        $signer = new Ed25519TokenSigner($privKeyBase64, $pubKeyBase64, $kid, $verifier);

        $payload = [
            'jti' => 'tok_test_deterministic_123',
            'iss' => 'license.katresnanku.com',
            'aud' => 'SPJ',
            'sub' => 'SPJ-****-****-****-****-0000',
            'dom' => 'example.com',
            'iat' => 1700000000,
            'nbf' => 1700000000,
            'exp' => 1700604800,
            'lic_exp' => null,
            'customer' => ['name' => 'Budi Santoso', 'email' => 'budi@example.com'],
        ];

        $token = $signer->sign($payload);
        $this->assertIsString($token);

        $verified = $verifier->verify($token);
        $this->assertSame($payload['jti'], $verified['jti']);
        $this->assertSame($payload['dom'], $verified['dom']);
        $this->assertSame($payload['aud'], $verified['aud']);
        $this->assertSame($payload['sub'], $verified['sub']);
    }

    public function test_rejects_tampered_signature(): void
    {
        $signer = new Ed25519TokenSigner();
        $registry = new StaticTrustedKeyRegistry([$signer->getKeyId() => $signer->getPublicKey()]);
        $verifier = new Ed25519TokenVerifier($registry);

        $payload = ['jti' => 'tok_tamper_sig', 'dom' => 'example.com'];
        $token = $signer->sign($payload);

        [$hdr, $pld, $sig] = explode('.', $token);

        // Flip first character of signature to alter signature bytes
        $tamperedSig = ($sig[0] === 'A' ? 'B' : 'A') . substr($sig, 1);
        $tamperedToken = "{$hdr}.{$pld}.{$tamperedSig}";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid token signature. Cryptographic verification failed.');
        $verifier->verify($tamperedToken);
    }

    public function test_rejects_tampered_payload(): void
    {
        $signer = new Ed25519TokenSigner();
        $registry = new StaticTrustedKeyRegistry([$signer->getKeyId() => $signer->getPublicKey()]);
        $verifier = new Ed25519TokenVerifier($registry);

        $payload = ['jti' => 'tok_tamper_payload', 'dom' => 'example.com'];
        $token = $signer->sign($payload);

        [$hdr, $pld, $sig] = explode('.', $token);

        $decodedPayload = json_decode(Ed25519TokenVerifier::base64UrlDecode($pld), true);
        $decodedPayload['dom'] = 'evil-example.com';
        $tamperedPld = Ed25519TokenVerifier::base64UrlEncode(json_encode($decodedPayload));
        $tamperedToken = "{$hdr}.{$tamperedPld}.{$sig}";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid token signature. Cryptographic verification failed.');
        $verifier->verify($tamperedToken);
    }

    public function test_rejects_algorithm_confusion_and_header_attacks(): void
    {
        $signer = new Ed25519TokenSigner();
        $registry = new StaticTrustedKeyRegistry([$signer->getKeyId() => $signer->getPublicKey()]);
        $verifier = new Ed25519TokenVerifier($registry);

        $invalidHeaders = [
            ['alg' => 'HS256', 'typ' => 'CLS-LIC-V1', 'kid' => $signer->getKeyId()],
            ['alg' => 'HS512', 'typ' => 'CLS-LIC-V1', 'kid' => $signer->getKeyId()],
            ['alg' => 'RS256', 'typ' => 'CLS-LIC-V1', 'kid' => $signer->getKeyId()],
            ['alg' => 'none', 'typ' => 'CLS-LIC-V1', 'kid' => $signer->getKeyId()],
            ['alg' => 'Ed25519', 'typ' => 'JWT', 'kid' => $signer->getKeyId()],
        ];

        foreach ($invalidHeaders as $hdr) {
            $hdrB64 = Ed25519TokenVerifier::base64UrlEncode(json_encode($hdr));
            $pldB64 = Ed25519TokenVerifier::base64UrlEncode(json_encode(['test' => 1]));
            $sigB64 = Ed25519TokenVerifier::base64UrlEncode(str_repeat('A', 64));
            $token = "{$hdrB64}.{$pldB64}.{$sigB64}";

            try {
                $verifier->verify($token);
                $this->fail('Expected RuntimeException for header: ' . json_encode($hdr));
            } catch (RuntimeException $e) {
                $this->assertStringContains_('Unsupported token header algorithm or type', $e->getMessage());
            }
        }
    }

    public function test_multi_key_registry_and_key_rotation(): void
    {
        // Setup two distinct keypairs for KEY_V1 and KEY_V2
        $keypair1 = sodium_crypto_sign_keypair();
        $sec1 = sodium_crypto_sign_secretkey($keypair1);
        $pub1 = sodium_crypto_sign_publickey($keypair1);

        $keypair2 = sodium_crypto_sign_keypair();
        $sec2 = sodium_crypto_sign_secretkey($keypair2);
        $pub2 = sodium_crypto_sign_publickey($keypair2);

        $registry = new StaticTrustedKeyRegistry([
            'KEY_V1' => $pub1,
            'KEY_V2' => $pub2,
        ]);

        $this->assertTrue($registry->hasKey('KEY_V1'));
        $this->assertTrue($registry->hasKey('KEY_V2'));
        $this->assertFalse($registry->hasKey('KEY_V3'));

        $verifier = new Ed25519TokenVerifier($registry);

        $signer1 = new Ed25519TokenSigner(base64_encode($sec1), base64_encode($pub1), 'KEY_V1', $verifier);
        $signer2 = new Ed25519TokenSigner(base64_encode($sec2), base64_encode($pub2), 'KEY_V2', $verifier);

        $token1 = $signer1->sign(['jti' => 'tok_key_1', 'version' => 1]);
        $token2 = $signer2->sign(['jti' => 'tok_key_2', 'version' => 2]);

        // Both tokens verify successfully against the multi-key registry
        $verified1 = $verifier->verify($token1);
        $this->assertSame('tok_key_1', $verified1['jti']);

        $verified2 = $verifier->verify($token2);
        $this->assertSame('tok_key_2', $verified2['jti']);

        // Token signed with unknown KEY_V3 fails closed
        $signer3 = new Ed25519TokenSigner(null, null, 'KEY_V3');
        $token3 = $signer3->sign(['jti' => 'tok_key_3']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown or unsupported key identifier (kid) [KEY_V3].');
        $verifier->verify($token3);
    }

    public function test_rejects_malformed_token_segments(): void
    {
        $registry = new StaticTrustedKeyRegistry();
        $verifier = new Ed25519TokenVerifier($registry);

        $malformedTokens = [
            'one_part_only',
            'header.payload',
            'header.payload.signature.extra',
            '..',
            '',
        ];

        foreach ($malformedTokens as $tok) {
            try {
                $verifier->verify($tok);
                $this->fail('Expected RuntimeException for token: ' . $tok);
            } catch (RuntimeException $e) {
                $this->assertStringContains_('Malformed token structure', $e->getMessage());
            }
        }
    }

    private function assertStringContains_(string $needle, string $haystack): void
    {
        $this->assertTrue(str_contains($haystack, $needle), "Failed asserting that '{$haystack}' contains '{$needle}'.");
    }
}

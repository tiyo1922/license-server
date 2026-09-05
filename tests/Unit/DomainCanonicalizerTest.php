<?php

namespace Tests\Unit;

use App\Exceptions\DomainCanonicalizationException;
use App\Services\License\DomainCanonicalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DomainCanonicalizerTest extends TestCase
{
    private DomainCanonicalizer $canonicalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->canonicalizer = new DomainCanonicalizer();
    }

    #[DataProvider('validDomainProvider')]
    public function test_canonicalizes_valid_domains_correctly(string $input, string $expected): void
    {
        $result = $this->canonicalizer->canonicalize($input);
        $this->assertSame($expected, $result);
    }

    public static function validDomainProvider(): array
    {
        return [
            'standard bare domain' => ['example.com', 'example.com'],
            'uppercase domain' => ['EXAMPLE.COM', 'example.com'],
            'surrounding whitespace' => ['  example.com  ', 'example.com'],
            'trailing dot' => ['example.com.', 'example.com'],
            'https scheme' => ['https://example.com', 'example.com'],
            'http scheme with path' => ['http://example.com/path/to/page', 'example.com'],
            'https with query' => ['https://example.com?a=1&b=2', 'example.com'],
            'https with fragment' => ['https://example.com#section', 'example.com'],
            'https with userinfo' => ['https://user:pass@example.com', 'example.com'],
            'explicit port 443' => ['example.com:443', 'example.com'],
            'explicit non-default port 8080' => ['example.com:8080', 'example.com'],
            'explicit non-default port 8443 with path' => ['https://example.com:8443/api', 'example.com'],
            'www subdomain' => ['www.example.com', 'www.example.com'],
            'nested subdomain' => ['sub.example.com', 'sub.example.com'],
            'deep subdomain' => ['api.v1.sub.example.com', 'api.v1.sub.example.com'],
            'german IDN munchen' => ['münchen.de', 'xn--mnchen-3ya.de'],
            'indonesian IDN' => ['buku.kopi.id', 'buku.kopi.id'],
            'localhost' => ['localhost', 'localhost'],
            'localhost with port' => ['http://localhost:8000', 'localhost'],
            'ipv4 address' => ['127.0.0.1', '127.0.0.1'],
            'ipv4 with port' => ['http://192.168.1.1:8080/admin', '192.168.1.1'],
            'ipv6 loopback bracketed' => ['[::1]', '::1'],
            'ipv6 loopback with port' => ['http://[::1]:8000/test', '::1'],
            'ipv6 unbracketed' => ['::1', '::1'],
            'complex url' => ['https://user:pass@SPJ.TEST:8443/activate?key=123#tok', 'spj.test'],
        ];
    }

    #[DataProvider('invalidDomainProvider')]
    public function test_rejects_invalid_or_malformed_domains(string $input): void
    {
        $this->expectException(DomainCanonicalizationException::class);
        $this->canonicalizer->canonicalize($input);
    }

    public static function invalidDomainProvider(): array
    {
        return [
            'empty string' => [''],
            'spaces only' => ['   '],
            'null byte control character' => ["example\x00.com"],
            'newline control character' => ["example\n.com"],
            'illegal punctuation' => ['invalid_host!#$'],
            'underscore in host' => ['invalid_host.com'],
            'single hyphen in label start' => ['-example.com'],
            'single hyphen in label end' => ['example-.com'],
            'invalid ipv4 octet' => ['127.000.000.1'],
            'ipv4 overflow' => ['999.999.999.999'],
            'invalid ipv6' => ['[:::1]'],
            'oversized string' => [str_repeat('a', 2049)],
            'oversized label' => [str_repeat('a', 64) . '.com'],
            'oversized hostname' => [str_repeat('a.', 130) . 'com'],
            'bare scheme' => ['http://'],
            'empty host after dot' => ['.'],
        ];
    }

    public function test_strict_www_non_equivalence(): void
    {
        $canonicalWithoutWww = $this->canonicalizer->canonicalize('example.com');
        $canonicalWithWww = $this->canonicalizer->canonicalize('www.example.com');

        $this->assertNotEquals($canonicalWithoutWww, $canonicalWithWww);
        $this->assertSame('example.com', $canonicalWithoutWww);
        $this->assertSame('www.example.com', $canonicalWithWww);
    }
}

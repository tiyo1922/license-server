<?php

namespace App\Services\License;

use App\Exceptions\DomainCanonicalizationException;

class DomainCanonicalizer
{
    /**
     * Canonicalize a domain name or URL into an authoritative host identity.
     *
     * @param string $input
     * @return string
     *
     * @throws DomainCanonicalizationException
     */
    public function canonicalize(string $input): string
    {
        // 1. Trim ASCII whitespace
        $trimmed = trim($input);

        // 2. Reject empty, oversized, or control-character containing input
        if ($trimmed === '' || strlen($trimmed) > 2048) {
            throw new DomainCanonicalizationException('Domain input must not be empty or exceed 2048 characters.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $trimmed)) {
            throw new DomainCanonicalizationException('Domain input contains illegal control characters.');
        }

        // Direct IPv6 check (e.g. "::1" or "[::1]")
        if (filter_var($trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($trimmed);
            return strtolower(inet_ntop($packed));
        }

        if (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
            $rawIpv6 = substr($trimmed, 1, -1);
            if (filter_var($rawIpv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $packed = inet_pton($rawIpv6);
                return strtolower(inet_ntop($packed));
            }
        }

        // 3. Parse URL authority
        if (str_contains($trimmed, '://') || str_starts_with($trimmed, '//')) {
            $parsed = parse_url($trimmed);
        } else {
            $parsed = parse_url('http://' . $trimmed);
        }

        if ($parsed === false || ! isset($parsed['host'])) {
            throw new DomainCanonicalizationException('Malformed domain or URL structure.');
        }

        $host = $parsed['host'];
        if ($host === '') {
            throw new DomainCanonicalizationException('Host component cannot be empty.');
        }

        // 4. Strip single trailing dot from FQDN representations
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        if ($host === '') {
            throw new DomainCanonicalizationException('Host component cannot be empty after stripping trailing dot.');
        }

        // 5. Handle IPv6 (e.g. "[::1]" or "::1")
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $rawIpv6 = substr($host, 1, -1);
            if (filter_var($rawIpv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $packed = inet_pton($rawIpv6);
                return strtolower(inet_ntop($packed));
            }
            throw new DomainCanonicalizationException('Malformed IPv6 address.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($host);
            return strtolower(inet_ntop($packed));
        }

        // 6. Handle IPv4
        if (preg_match('/^[0-9.]+$/', $host)) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $host;
            }
            throw new DomainCanonicalizationException('Invalid or non-standard IPv4 address.');
        }

        // 7. Handle IDN / Unicode using UTS #46 Punycode
        if (preg_match('/[^\x20-\x7E]/', $host)) {
            $asciiHost = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($asciiHost === false || $asciiHost === '') {
                throw new DomainCanonicalizationException('Failed to convert internationalized domain to Punycode.');
            }
            $host = $asciiHost;
        }

        // 8. Lowercase ASCII
        $host = strtolower($host);

        // 9. Hostname syntax validation
        if ($host === 'localhost') {
            return 'localhost';
        }

        if (strlen($host) > 253) {
            throw new DomainCanonicalizationException('Canonical hostname exceeds maximum length of 253 octets.');
        }

        $rfcRegex = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$/';
        if (! preg_match($rfcRegex, $host)) {
            throw new DomainCanonicalizationException('Invalid hostname syntax according to RFC 1123.');
        }

        return $host;
    }
}

<?php

namespace App\Services\License\Client;

class VerificationResult
{
    public function __construct(
        public readonly VerificationStatusCode $statusCode,
        public readonly bool $isValid,
        public readonly ?array $payload = null,
        public readonly ?array $header = null,
        public readonly ?string $errorMessage = null,
        public readonly ?int $evaluatedAt = null
    ) {}

    public static function valid(array $payload, array $header, int $evaluatedAt): self
    {
        return new self(
            statusCode: VerificationStatusCode::VALID,
            isValid: true,
            payload: $payload,
            header: $header,
            errorMessage: null,
            evaluatedAt: $evaluatedAt
        );
    }

    public static function invalid(
        VerificationStatusCode $statusCode,
        string $errorMessage,
        ?array $header = null,
        ?array $payload = null,
        ?int $evaluatedAt = null
    ): self {
        return new self(
            statusCode: $statusCode,
            isValid: false,
            payload: $payload,
            header: $header,
            errorMessage: $errorMessage,
            evaluatedAt: $evaluatedAt
        );
    }

    public function getJti(): ?string
    {
        return $this->payload['jti'] ?? null;
    }

    public function getMaskedKey(): ?string
    {
        return $this->payload['sub'] ?? null;
    }

    public function getCanonicalDomain(): ?string
    {
        return $this->payload['dom'] ?? null;
    }

    public function getExpiresAt(): ?int
    {
        return isset($this->payload['exp']) ? (int) $this->payload['exp'] : null;
    }

    public function getLicenseExpiresAt(): ?int
    {
        return isset($this->payload['lic_exp']) ? (int) $this->payload['lic_exp'] : null;
    }

    public function isLifetime(): bool
    {
        return array_key_exists('lic_exp', $this->payload ?? [])
            ? $this->payload['lic_exp'] === null
            : false;
    }

    public function getCustomer(): ?array
    {
        return $this->payload['customer'] ?? null;
    }

    public function toArray(): array
    {
        return [
            'is_valid' => $this->isValid,
            'status' => $this->statusCode->value,
            'error_message' => $this->errorMessage,
            'jti' => $this->getJti(),
            'masked_key' => $this->getMaskedKey(),
            'canonical_domain' => $this->getCanonicalDomain(),
            'expires_at' => $this->getExpiresAt(),
            'license_expires_at' => $this->getLicenseExpiresAt(),
            'is_lifetime' => $this->isLifetime(),
            'customer' => $this->getCustomer(),
            'evaluated_at' => $this->evaluatedAt,
        ];
    }
}

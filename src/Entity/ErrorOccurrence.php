<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Entity;

class ErrorOccurrence
{
    private ?int $id = null;
    private ErrorFingerprint $fingerprint;
    private \DateTimeImmutable $occurredAt;
    /** @var array<string, mixed> */
    private array $context = [];
    private ?string $requestUri = null;
    private ?string $method = null;
    private ?string $userRef = null;
    private ?string $tracePreview = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFingerprint(): ErrorFingerprint
    {
        return $this->fingerprint;
    }

    public function setFingerprint(ErrorFingerprint $fingerprint): void
    {
        $this->fingerprint = $fingerprint;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): void
    {
        $this->occurredAt = $occurredAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    public function getRequestUri(): ?string
    {
        return $this->requestUri;
    }

    public function setRequestUri(?string $requestUri): void
    {
        $this->requestUri = $requestUri;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    public function setMethod(?string $method): void
    {
        $this->method = $method;
    }

    public function getUserRef(): ?string
    {
        return $this->userRef;
    }

    public function setUserRef(?string $userRef): void
    {
        $this->userRef = $userRef;
    }

    public function getTracePreview(): ?string
    {
        return $this->tracePreview;
    }

    public function setTracePreview(?string $tracePreview): void
    {
        $this->tracePreview = $tracePreview;
    }
}

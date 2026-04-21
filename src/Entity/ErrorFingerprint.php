<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Entity;

class ErrorFingerprint
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_MUTED = 'muted';

    private ?int $id = null;
    private string $fingerprint;
    private int $level;
    private string $levelName;
    private string $message;
    private ?string $exceptionClass = null;
    private ?string $file = null;
    private ?int $line = null;
    private string $channel;
    private string $environment;
    private \DateTimeImmutable $firstSeenAt;
    private \DateTimeImmutable $lastSeenAt;
    private int $occurrenceCount = 0;
    private string $status = self::STATUS_OPEN;
    private ?\DateTimeImmutable $resolvedAt = null;
    private ?string $assigneeId = null;
    private ?\DateTimeImmutable $notifiedInDigestAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(string $fingerprint): void
    {
        $this->fingerprint = $fingerprint;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): void
    {
        $this->level = $level;
    }

    public function getLevelName(): string
    {
        return $this->levelName;
    }

    public function setLevelName(string $levelName): void
    {
        $this->levelName = $levelName;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): void
    {
        $this->message = $message;
    }

    public function getExceptionClass(): ?string
    {
        return $this->exceptionClass;
    }

    public function setExceptionClass(?string $exceptionClass): void
    {
        $this->exceptionClass = $exceptionClass;
    }

    public function getFile(): ?string
    {
        return $this->file;
    }

    public function setFile(?string $file): void
    {
        $this->file = $file;
    }

    public function getLine(): ?int
    {
        return $this->line;
    }

    public function setLine(?int $line): void
    {
        $this->line = $line;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): void
    {
        $this->channel = $channel;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function setEnvironment(string $environment): void
    {
        $this->environment = $environment;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function setFirstSeenAt(\DateTimeImmutable $firstSeenAt): void
    {
        $this->firstSeenAt = $firstSeenAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(\DateTimeImmutable $lastSeenAt): void
    {
        $this->lastSeenAt = $lastSeenAt;
    }

    public function getOccurrenceCount(): int
    {
        return $this->occurrenceCount;
    }

    public function setOccurrenceCount(int $occurrenceCount): void
    {
        $this->occurrenceCount = $occurrenceCount;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): void
    {
        $this->resolvedAt = $resolvedAt;
    }

    public function getAssigneeId(): ?string
    {
        return $this->assigneeId;
    }

    public function setAssigneeId(?string $assigneeId): void
    {
        $this->assigneeId = $assigneeId;
    }

    public function getNotifiedInDigestAt(): ?\DateTimeImmutable
    {
        return $this->notifiedInDigestAt;
    }

    public function setNotifiedInDigestAt(?\DateTimeImmutable $notifiedInDigestAt): void
    {
        $this->notifiedInDigestAt = $notifiedInDigestAt;
    }
}

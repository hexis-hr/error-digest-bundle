<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Digest;

/**
 * Read-only projection of an err_fingerprint row for digest rendering.
 */
final readonly class FingerprintSummary
{
    public function __construct(
        public int $id,
        public string $fingerprint,
        public int $level,
        public string $levelName,
        public string $message,
        public ?string $exceptionClass,
        public ?string $file,
        public ?int $line,
        public string $channel,
        public string $environment,
        public \DateTimeImmutable $firstSeenAt,
        public \DateTimeImmutable $lastSeenAt,
        public int $occurrenceCount,
        public string $status,
        public ?string $assigneeId = null,
        public int $windowOccurrences = 0,
        public ?float $velocityMultiplier = null,
    ) {
    }

    public function shortFingerprint(): string
    {
        return substr($this->fingerprint, 0, 8);
    }
}

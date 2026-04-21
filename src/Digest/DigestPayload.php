<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Digest;

/**
 * Rendered shape sent to a DigestSender. Each section is a list of summaries;
 * the template iterates only sections the host has configured.
 */
final readonly class DigestPayload
{
    /**
     * @param array<string, list<FingerprintSummary>> $sections Keyed by section name (new, spiking, top, stale).
     * @param array<string, int>                       $levelCounts Aggregate occurrence counts by level_name for the window.
     */
    public function __construct(
        public \DateTimeImmutable $generatedAt,
        public \DateTimeImmutable $windowStart,
        public \DateTimeImmutable $windowEnd,
        public string $environment,
        public array $sections,
        public array $levelCounts,
    ) {
    }

    public function isEmpty(): bool
    {
        foreach ($this->sections as $rows) {
            if ($rows !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<int> All fingerprint ids appearing anywhere in the payload, deduplicated.
     */
    public function allFingerprintIds(): array
    {
        $ids = [];
        foreach ($this->sections as $rows) {
            foreach ($rows as $row) {
                $ids[$row->id] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }
}

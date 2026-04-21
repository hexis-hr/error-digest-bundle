<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Storage;

/**
 * Port for buffer persistence. The Monolog handler talks to this; DbalWriter
 * is the default adapter. Exists primarily so handlers can be unit-tested
 * against a fake or mock without reaching a real database.
 */
interface Writer
{
    /**
     * @param array<string, BufferedRecord> $buffer Keyed by fingerprint.
     */
    public function flush(array $buffer, string $environment): void;

    public function prune(\DateTimeImmutable $threshold): int;
}

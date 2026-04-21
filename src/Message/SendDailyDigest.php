<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Message;

/**
 * Marker message asking the bundle to build the digest and fan it out to all
 * configured senders. Scheduler or console command dispatches it; consumer
 * handles sync (default) or async via a configured Messenger transport.
 */
final readonly class SendDailyDigest
{
    public function __construct(
        public ?\DateTimeImmutable $now = null,
        public bool $dryRun = false,
    ) {
    }
}

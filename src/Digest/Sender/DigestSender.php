<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Digest\Sender;

use Hexis\ErrorDigestBundle\Digest\DigestPayload;

interface DigestSender
{
    /**
     * A stable identifier used by config (e.g. "mailer", "notifier") so the host
     * can enable/disable senders via the `error_digest.digest.senders` list.
     */
    public function name(): string;

    public function send(DigestPayload $payload): void;
}

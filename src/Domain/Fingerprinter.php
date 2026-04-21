<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Domain;

use Monolog\LogRecord;

interface Fingerprinter
{
    /**
     * Produce a stable hex-encoded hash that identifies a unique error signature.
     */
    public function fingerprint(LogRecord $record): string;
}

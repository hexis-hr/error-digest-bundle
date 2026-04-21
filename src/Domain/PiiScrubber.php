<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Domain;

interface PiiScrubber
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function scrub(array $context): array;
}

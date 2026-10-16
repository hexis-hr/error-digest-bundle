<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Integration;

use Hexis\ErrorDigestBundle\Domain\DefaultPiiScrubber;
use Hexis\ErrorDigestBundle\Domain\PiiScrubber;

/**
 * The stricter scrubber {@see TestKernel} configures as error_digest.scrubber: the default
 * rules plus e-mail addresses.
 */
final class EmailMaskingScrubber implements PiiScrubber
{
    public function scrub(array $context): array
    {
        $mask = static function (mixed $value) use (&$mask): mixed {
            return match (true) {
                \is_array($value) => array_map($mask, $value),
                \is_string($value) => preg_replace('/[^\s@]+@[^\s@]+\.[a-z]{2,}/i', '[EMAIL]', $value),
                default => $value,
            };
        };

        return $mask((new DefaultPiiScrubber())->scrub($context));
    }
}

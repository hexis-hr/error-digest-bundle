<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Js;

/**
 * A single browser-reported error, after validation.
 *
 * Values already trimmed/truncated to safe caps by JsPayloadValidator.
 */
final readonly class JsEvent
{
    public function __construct(
        public string $message,
        public ?string $exceptionType,
        public ?string $sourceUrl,
        public ?int $line,
        public ?int $column,
        public ?string $stack,
        public ?string $pageUrl,
        public ?string $userAgent,
        public ?string $release,
        public ?string $userRef,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Js;

/**
 * Turns the raw JSON body of an ingest POST into a safe JsEvent, or throws.
 * Enforces field caps so a malicious client can't balloon stored rows.
 */
final class JsPayloadValidator
{
    private const MESSAGE_CAP = 2000;
    private const SOURCE_URL_CAP = 2048;
    private const PAGE_URL_CAP = 2048;
    private const USER_AGENT_CAP = 512;
    private const EXCEPTION_TYPE_CAP = 80;
    private const USER_REF_CAP = 128;
    private const RELEASE_CAP = 80;

    public function __construct(
        private readonly int $maxStackLines = 50,
        private readonly int $maxStackLineLength = 300,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function validate(array $payload): JsEvent
    {
        $message = $this->trimmedString($payload['message'] ?? null, self::MESSAGE_CAP);
        if ($message === null || $message === '') {
            throw new InvalidJsPayloadException('message is required');
        }

        return new JsEvent(
            message: $message,
            exceptionType: $this->trimmedString($payload['type'] ?? null, self::EXCEPTION_TYPE_CAP),
            sourceUrl: $this->trimmedString($payload['source'] ?? null, self::SOURCE_URL_CAP),
            line: $this->intOrNull($payload['line'] ?? null),
            column: $this->intOrNull($payload['column'] ?? null),
            stack: $this->clampStack($payload['stack'] ?? null),
            pageUrl: $this->trimmedString($payload['url'] ?? null, self::PAGE_URL_CAP),
            userAgent: $this->trimmedString($payload['user_agent'] ?? null, self::USER_AGENT_CAP),
            release: $this->trimmedString($payload['release'] ?? null, self::RELEASE_CAP),
            userRef: $this->trimmedString($payload['user'] ?? null, self::USER_REF_CAP),
            occurredAt: new \DateTimeImmutable(),
        );
    }

    private function trimmedString(mixed $value, int $cap): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $cap) {
            return mb_substr($value, 0, $cap);
        }

        return $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (\is_string($value) && ctype_digit($value)) {
            $int = (int) $value;

            return $int > 0 ? $int : null;
        }

        return null;
    }

    private function clampStack(mixed $stack): ?string
    {
        if (!\is_string($stack)) {
            return null;
        }

        $stack = trim($stack);
        if ($stack === '') {
            return null;
        }

        $lines = preg_split('/\r?\n/', $stack);
        if ($lines === false) {
            return null;
        }

        $lines = \array_slice($lines, 0, $this->maxStackLines);

        $lines = array_map(function (string $line): string {
            if (mb_strlen($line) > $this->maxStackLineLength) {
                return mb_substr($line, 0, $this->maxStackLineLength) . '…';
            }

            return $line;
        }, $lines);

        return implode("\n", $lines);
    }
}

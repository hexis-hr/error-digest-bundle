<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Domain;

/**
 * Puts everything the writers store through the configured PiiScrubber: free text (message,
 * trace, URLs, user reference) and context. Whatever the scrubber returns, a text it drops or
 * turns into a non-string is stored as [REDACTED], and objects are stored by class name only.
 *
 * @internal
 */
final class StorageScrubber
{
    private const REDACTED = '[REDACTED]';

    public function __construct(private readonly PiiScrubber $scrubber)
    {
    }

    /**
     * @param array<string, ?string> $texts
     * @return array<string, ?string>
     */
    public function texts(array $texts): array
    {
        $scrubbed = $this->scrubber->scrub($texts);

        foreach ($texts as $key => $text) {
            $value = $scrubbed[$key] ?? null;
            $texts[$key] = $text === null ? null : (\is_string($value) ? $value : self::REDACTED);
        }

        return $texts;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function context(array $context): array
    {
        return self::objectsByClassName($this->scrubber->scrub($context));
    }

    /**
     * The URL without its query string and fragment, which carry tokens and search terms.
     */
    public static function withoutQuery(?string $url): ?string
    {
        return $url === null ? null : (string) preg_replace('/[?#].*$/s', '', $url);
    }

    /**
     * @param array<mixed, mixed> $data
     * @return array<mixed, mixed>
     */
    private static function objectsByClassName(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = self::objectsByClassName($value);
            } elseif (\is_object($value)) {
                $data[$key] = '[object ' . $value::class . ']';
            }
        }

        return $data;
    }
}

<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Domain;

final class DefaultPiiScrubber implements PiiScrubber
{
    private const REDACTED = '[REDACTED]';

    /** Keys whose values are replaced wholesale (case-insensitive exact match). */
    private const SENSITIVE_KEYS = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'api_key', 'apikey',
        'authorization', 'auth', 'cookie', 'session', 'csrf', 'jwt',
        'access_token', 'refresh_token', 'client_secret', 'private_key',
    ];

    /**
     * Pattern => replacement, in this order: query strings, fragments and credentials of URLs
     * before e-mail addresses, so user:password@host is not read as an address.
     */
    private const TEXT_PATTERNS = [
        '#\b([a-z][a-z0-9+.-]*://[^\s?\#"\'<>]*)([?\#])[^\s"\'<>]*#i' => '$1$2' . self::REDACTED,
        '#\b([a-z][a-z0-9+.-]*://)[^/\s@?\#"\'<>]+@#i' => '$1' . self::REDACTED . '@',
        '/[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{1,253}\.[A-Za-z]{2,63}/' => self::REDACTED,
        '/\b(?:\d[ -]*?){13,19}\b/' => self::REDACTED,
        '/\bBearer\s+[A-Za-z0-9\-_\.]+\b/i' => 'Bearer ' . self::REDACTED,
        '/eyJ[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+/' => self::REDACTED,
    ];

    public function scrub(array $context): array
    {
        return $this->walk($context);
    }

    /**
     * Objects are kept by class name only: their properties never reach storage.
     *
     * @param array<mixed, mixed> $data
     * @return array<mixed, mixed>
     */
    private function walk(array $data): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = match (true) {
                \is_string($key) && $this->isSensitiveKey($key) => self::REDACTED,
                \is_array($value) => $this->walk($value),
                \is_string($value) => $this->maskValue($value),
                \is_int($value) => $this->maskValue((string) $value) === (string) $value ? $value : self::REDACTED,
                \is_object($value) => '[object ' . $value::class . ']',
                default => $value,
            };
        }

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        $needle = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($needle === $sensitive || str_contains($needle, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private function maskValue(string $value): string
    {
        // A pattern that fails (backtrack limit, say) masks the whole value: fail closed.
        return preg_replace(array_keys(self::TEXT_PATTERNS), array_values(self::TEXT_PATTERNS), $value) ?? self::REDACTED;
    }
}

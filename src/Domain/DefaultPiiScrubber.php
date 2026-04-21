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

    public function scrub(array $context): array
    {
        return $this->walk($context);
    }

    /**
     * @param array<mixed, mixed> $data
     * @return array<mixed, mixed>
     */
    private function walk(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = self::REDACTED;
                continue;
            }

            if (\is_array($value)) {
                $data[$key] = $this->walk($value);
                continue;
            }

            if (\is_string($value)) {
                $data[$key] = $this->maskValue($value);
            }
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
        $value = preg_replace('/\b(?:\d[ -]*?){13,19}\b/', self::REDACTED, $value) ?? $value;
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9\-_\.]+\b/i', 'Bearer ' . self::REDACTED, $value) ?? $value;
        $value = preg_replace('/eyJ[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+/', self::REDACTED, $value) ?? $value;

        return $value;
    }
}

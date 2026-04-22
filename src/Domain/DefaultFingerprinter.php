<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Domain;

use Monolog\LogRecord;

final class DefaultFingerprinter implements Fingerprinter
{
    public function fingerprint(LogRecord $record): string
    {
        $exception = $record->context['exception'] ?? null;

        if ($exception instanceof \Throwable) {
            $parts = [
                $exception::class,
                $exception->getFile(),
                (string) $exception->getLine(),
                $this->normalizeMessage($exception->getMessage()),
            ];
        } else {
            $parts = [
                'log',
                $record->channel,
                (string) $record->level->value,
                $this->normalizeMessage($record->message),
            ];
        }

        return hash('sha256', implode('|', $parts));
    }

    private function normalizeMessage(string $message): string
    {
        // UUIDs first — they match the digit regex otherwise and we want the UUID token, not 0-0-0.
        $message = preg_replace('/[0-9a-fA-F]{8}(-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}/', 'UUID', $message) ?? $message;

        // Long hex runs (checksums, hashes).
        $message = preg_replace('/\b[0-9a-fA-F]{16,}\b/', 'HASH', $message) ?? $message;

        // Email addresses before generic quoted-string collapse so we get EMAIL, not "?".
        $message = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', 'EMAIL', $message) ?? $message;

        // URLs.
        $message = preg_replace('#https?://[^\s"\'<>]+#', 'URL', $message) ?? $message;

        // Quoted identifiers — hostnames, paths, user-supplied values all surface as "…" in
        // framework exceptions and vary per request. Collapse them so the fingerprint captures
        // the exception shape, not the incident value. 200-char cap + no-newline keeps this safe.
        $message = preg_replace('/"[^"\r\n]{1,200}"/', '"?"', $message) ?? $message;
        $message = preg_replace("/'[^'\r\n]{1,200}'/", "'?'", $message) ?? $message;

        // Any run of digits anywhere — previously \b\d+\b missed digits adjacent to word chars
        // (e.g. `solo_invoice_7-1-1.pdf` — the `_7` side has no word boundary). Using \d+ without
        // word boundaries catches those embedded integers.
        $message = preg_replace('/\d+/', '0', $message) ?? $message;

        // Collapse repeated slashes that can appear when paths are concatenated sloppily
        // (e.g. `/mnt/scanner//file.pdf`) so fingerprints don't split on formatting noise.
        $message = preg_replace('#/{2,}#', '/', $message) ?? $message;

        return trim($message);
    }
}

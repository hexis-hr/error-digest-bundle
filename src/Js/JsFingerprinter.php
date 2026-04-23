<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Js;

/**
 * Produces a stable hex fingerprint for browser errors so repeat occurrences
 * of the same JS bug collapse into one `err_fingerprint` row.
 *
 * Strategy mirrors the PHP DefaultFingerprinter: combine exception shape +
 * originating source + normalized message + top stack frame so incident-value
 * noise (user ids, timestamps, query params) doesn't split the signature.
 */
final class JsFingerprinter
{
    public function fingerprint(JsEvent $event): string
    {
        $parts = [
            $event->exceptionType ?? 'js',
            $this->normalizeUrl($event->sourceUrl),
            (string) ($event->line ?? 0),
            $this->normalizeMessage($event->message),
            $this->firstFrame($event->stack),
        ];

        return hash('sha256', implode('|', $parts));
    }

    private function normalizeUrl(?string $url): string
    {
        if ($url === null || $url === '') {
            return '?';
        }

        // Drop query string + hash — cache-busting params (?v=abc123) vary per release.
        $url = preg_replace('/[?#].*$/', '', $url) ?? $url;

        // Collapse asset-hash-style filename suffixes (bundle.abc123.js → bundle.HASH.js).
        $url = preg_replace('/\.[0-9a-fA-F]{6,}\.(js|mjs)$/', '.HASH.$1', $url) ?? $url;

        return $url;
    }

    private function normalizeMessage(string $message): string
    {
        $message = preg_replace('/[0-9a-fA-F]{8}(-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}/', 'UUID', $message) ?? $message;
        $message = preg_replace('/\b[0-9a-fA-F]{16,}\b/', 'HASH', $message) ?? $message;
        $message = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', 'EMAIL', $message) ?? $message;
        $message = preg_replace('#https?://[^\s"\'<>]+#', 'URL', $message) ?? $message;
        $message = preg_replace('/"[^"\r\n]{1,200}"/', '"?"', $message) ?? $message;
        $message = preg_replace("/'[^'\r\n]{1,200}'/", "'?'", $message) ?? $message;
        $message = preg_replace('/\d+/', '0', $message) ?? $message;

        return trim($message);
    }

    /**
     * Extract the topmost stack frame (function + file) so the fingerprint
     * discriminates between errors that share a message but come from
     * different code paths (e.g. two callers both throwing "TypeError").
     */
    private function firstFrame(?string $stack): string
    {
        if ($stack === null || $stack === '') {
            return '';
        }

        $lines = preg_split('/\r?\n/', trim($stack));
        if ($lines === false || $lines === []) {
            return '';
        }

        foreach ($lines as $line) {
            $line = trim($line);
            // Skip the error-message line that some browsers put first.
            if ($line === '' || !str_contains($line, '@') && !str_contains($line, 'at ')) {
                continue;
            }

            // Strip line:col suffixes so cosmetic minifier shifts don't split the fingerprint.
            $line = preg_replace('/:\d+:\d+\)?$/', '', $line) ?? $line;
            $line = preg_replace('/:\d+\)?$/', '', $line) ?? $line;

            return $line;
        }

        return '';
    }
}

<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Js;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Per-IP fixed-window limiter. Not atomic — two concurrent requests from the
 * same IP can both pass even at the boundary — which is acceptable for a JS
 * ingest endpoint whose main job is to prevent single-client spam floods, not
 * to enforce a hard contract.
 *
 * Uses PSR-6 cache so the bundle doesn't depend on symfony/rate-limiter.
 */
final class JsRateLimiter
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly int $maxRequestsPerMinute,
    ) {
    }

    public function accept(string $clientIdentifier): bool
    {
        if ($this->maxRequestsPerMinute <= 0) {
            return true;
        }

        $key = 'error_digest.js.rate.' . hash('sha256', $clientIdentifier);
        $item = $this->cache->getItem($key);

        $count = $item->isHit() ? (int) $item->get() : 0;
        if ($count >= $this->maxRequestsPerMinute) {
            return false;
        }

        $item->set($count + 1);
        $item->expiresAfter(60);
        $this->cache->save($item);

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Unit\Js;

use Hexis\ErrorDigestBundle\Js\JsRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class JsRateLimiterTest extends TestCase
{
    public function testAcceptsUpToLimitThenRejects(): void
    {
        $limiter = new JsRateLimiter(new ArrayAdapter(), maxRequestsPerMinute: 3);

        self::assertTrue($limiter->accept('1.2.3.4'));
        self::assertTrue($limiter->accept('1.2.3.4'));
        self::assertTrue($limiter->accept('1.2.3.4'));
        self::assertFalse($limiter->accept('1.2.3.4'));
    }

    public function testZeroLimitDisablesRateLimiting(): void
    {
        $limiter = new JsRateLimiter(new ArrayAdapter(), maxRequestsPerMinute: 0);

        for ($i = 0; $i < 100; $i++) {
            self::assertTrue($limiter->accept('1.2.3.4'));
        }
    }

    public function testDifferentClientsHaveSeparateBuckets(): void
    {
        $limiter = new JsRateLimiter(new ArrayAdapter(), maxRequestsPerMinute: 2);

        self::assertTrue($limiter->accept('1.2.3.4'));
        self::assertTrue($limiter->accept('1.2.3.4'));
        self::assertFalse($limiter->accept('1.2.3.4'));

        self::assertTrue($limiter->accept('5.6.7.8'));
    }
}

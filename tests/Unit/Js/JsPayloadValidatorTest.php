<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Unit\Js;

use Hexis\ErrorDigestBundle\Js\InvalidJsPayloadException;
use Hexis\ErrorDigestBundle\Js\JsPayloadValidator;
use PHPUnit\Framework\TestCase;

final class JsPayloadValidatorTest extends TestCase
{
    private JsPayloadValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new JsPayloadValidator(maxStackLines: 5, maxStackLineLength: 60);
    }

    public function testRequiresMessage(): void
    {
        $this->expectException(InvalidJsPayloadException::class);
        $this->validator->validate([]);
    }

    public function testRejectsEmptyMessage(): void
    {
        $this->expectException(InvalidJsPayloadException::class);
        $this->validator->validate(['message' => '   ']);
    }

    public function testProducesEventWithCoreFields(): void
    {
        $event = $this->validator->validate([
            'message' => 'Cannot read property foo of undefined',
            'type' => 'TypeError',
            'source' => 'https://app.example.com/bundle.js',
            'line' => 42,
            'column' => 15,
            'url' => 'https://app.example.com/dashboard',
            'user_agent' => 'Mozilla/5.0',
            'release' => 'v1.2.3',
        ]);

        self::assertSame('Cannot read property foo of undefined', $event->message);
        self::assertSame('TypeError', $event->exceptionType);
        self::assertSame('https://app.example.com/bundle.js', $event->sourceUrl);
        self::assertSame(42, $event->line);
        self::assertSame(15, $event->column);
        self::assertSame('v1.2.3', $event->release);
    }

    public function testCoercesStringIntegerFields(): void
    {
        $event = $this->validator->validate([
            'message' => 'boom',
            'line' => '42',
            'column' => '15',
        ]);

        self::assertSame(42, $event->line);
        self::assertSame(15, $event->column);
    }

    public function testDropsZeroAndNegativeLineNumbers(): void
    {
        $event = $this->validator->validate([
            'message' => 'boom',
            'line' => 0,
            'column' => -1,
        ]);

        self::assertNull($event->line);
        self::assertNull($event->column);
    }

    public function testTrimsStackToConfiguredMaxLines(): void
    {
        $stack = implode("\n", array_fill(0, 20, 'at frame()'));
        $event = $this->validator->validate([
            'message' => 'boom',
            'stack' => $stack,
        ]);

        self::assertIsString($event->stack);
        self::assertCount(5, explode("\n", $event->stack));
    }

    public function testTruncatesOverlongStackLines(): void
    {
        $long = str_repeat('x', 200);
        $event = $this->validator->validate([
            'message' => 'boom',
            'stack' => "short\n{$long}",
        ]);

        $lines = explode("\n", (string) $event->stack);
        self::assertSame('short', $lines[0]);
        self::assertLessThanOrEqual(61, mb_strlen($lines[1])); // 60 + ellipsis
        self::assertStringEndsWith('…', $lines[1]);
    }

    public function testNonArrayInputReturnsNullFields(): void
    {
        $event = $this->validator->validate([
            'message' => 'boom',
            'type' => ['nested'],
            'line' => 'not-a-number',
        ]);

        self::assertNull($event->exceptionType);
        self::assertNull($event->line);
    }
}

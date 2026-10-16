<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Unit\Domain;

use Hexis\ErrorDigestBundle\Domain\DefaultPiiScrubber;
use PHPUnit\Framework\TestCase;

final class DefaultPiiScrubberTest extends TestCase
{
    private DefaultPiiScrubber $scrubber;

    protected function setUp(): void
    {
        $this->scrubber = new DefaultPiiScrubber();
    }

    public function testRedactsSensitiveKeys(): void
    {
        $input = [
            'username' => 'alice',
            'password' => 'hunter2',
            'api_key' => 'sk_live_xxx',
            'nested' => [
                'refresh_token' => 'abc',
            ],
        ];

        $scrubbed = $this->scrubber->scrub($input);

        self::assertSame('alice', $scrubbed['username']);
        self::assertSame('[REDACTED]', $scrubbed['password']);
        self::assertSame('[REDACTED]', $scrubbed['api_key']);
        self::assertSame('[REDACTED]', $scrubbed['nested']['refresh_token']);
    }

    public function testKeyMatchIsCaseInsensitive(): void
    {
        $scrubbed = $this->scrubber->scrub(['Authorization' => 'Bearer abc']);

        self::assertSame('[REDACTED]', $scrubbed['Authorization']);
    }

    public function testKeyMatchCatchesSubstringVariants(): void
    {
        $scrubbed = $this->scrubber->scrub([
            'user_password_hash' => 'abc',
            'csrf_token_value' => 'xyz',
            'safe_field' => 'ok',
        ]);

        self::assertSame('[REDACTED]', $scrubbed['user_password_hash']);
        self::assertSame('[REDACTED]', $scrubbed['csrf_token_value']);
        self::assertSame('ok', $scrubbed['safe_field']);
    }

    public function testMaskCreditCardLikeNumbers(): void
    {
        $scrubbed = $this->scrubber->scrub([
            'message' => 'Processed 4111 1111 1111 1111 today',
        ]);

        self::assertStringContainsString('[REDACTED]', $scrubbed['message']);
        self::assertStringNotContainsString('4111 1111 1111 1111', $scrubbed['message']);
    }

    public function testMaskBearerTokens(): void
    {
        $scrubbed = $this->scrubber->scrub([
            'trace' => 'Authorization: Bearer abc.def.ghi',
        ]);

        self::assertStringContainsString('Bearer [REDACTED]', $scrubbed['trace']);
    }

    public function testMaskJwtShapedStrings(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjMifQ.signature-part';
        $scrubbed = $this->scrubber->scrub(['note' => "got token $jwt here"]);

        self::assertStringNotContainsString($jwt, $scrubbed['note']);
        self::assertStringContainsString('[REDACTED]', $scrubbed['note']);
    }

    public function testLeavesNonStringScalarsAlone(): void
    {
        $scrubbed = $this->scrubber->scrub([
            'count' => 42,
            'enabled' => true,
            'threshold' => 3.14,
        ]);

        self::assertSame(42, $scrubbed['count']);
        self::assertTrue($scrubbed['enabled']);
        self::assertSame(3.14, $scrubbed['threshold']);
    }

    public function testMasksIntegersThatLookLikeCardNumbers(): void
    {
        $scrubbed = $this->scrubber->scrub(['card' => 4111111111111111, 'nested' => [5500000000000004], 'id' => 123456789012]);

        self::assertSame('[REDACTED]', $scrubbed['card']);
        self::assertSame(['[REDACTED]'], $scrubbed['nested']);
        self::assertSame(123456789012, $scrubbed['id'], 'Twelve digits are too short for a card number.');
    }

    public function testMasksEmailAddresses(): void
    {
        $scrubbed = $this->scrubber->scrub(['note' => 'Partner jane.doe+mis@example.co.uk could not be saved']);

        self::assertSame('Partner [REDACTED] could not be saved', $scrubbed['note']);
    }

    public function testMasksQueryStringsAndFragmentsOfUrls(): void
    {
        $scrubbed = $this->scrubber->scrub([
            'query' => 'GET https://api.example.com/v1/partners?email=jane%40example.com&page=2 failed',
            'fragment' => 'Redirected to https://example.com/callback#access_token=abc',
            'plain' => 'See https://example.com/docs/errors for details',
        ]);

        self::assertSame('GET https://api.example.com/v1/partners?[REDACTED] failed', $scrubbed['query']);
        self::assertSame('Redirected to https://example.com/callback#[REDACTED]', $scrubbed['fragment']);
        self::assertSame('See https://example.com/docs/errors for details', $scrubbed['plain']);
    }

    public function testMasksCredentialsInUrls(): void
    {
        $scrubbed = $this->scrubber->scrub(['dsn' => 'Connection to mysql://app:s3cret@db:3306/mis refused']);

        self::assertSame('Connection to mysql://[REDACTED]@db:3306/mis refused', $scrubbed['dsn']);
    }

    public function testKeepsObjectsByClassNameOnly(): void
    {
        $partner = new class {
            public string $email = 'jane@example.com';
            public string $name = 'Jane Doe';
        };

        $scrubbed = $this->scrubber->scrub(['partner' => $partner, 'nested' => ['at' => new \DateTimeImmutable('2026-10-15')]]);

        self::assertSame('[object ' . $partner::class . ']', $scrubbed['partner']);
        self::assertSame('[object DateTimeImmutable]', $scrubbed['nested']['at']);
    }

    public function testMasksTheWholeValueWhenAPatternFails(): void
    {
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $scrubbed = $this->scrubber->scrub(['message' => 'Card 4111 1111 1111 1111 declined', 'count' => 4111111111111111]);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        self::assertSame('[REDACTED]', $scrubbed['message']);
        self::assertSame('[REDACTED]', $scrubbed['count']);
    }
}

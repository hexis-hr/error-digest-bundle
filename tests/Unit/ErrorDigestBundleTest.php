<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Unit;

use Hexis\ErrorDigestBundle\Domain\DefaultPiiScrubber;
use Hexis\ErrorDigestBundle\Domain\PiiScrubber;
use Hexis\ErrorDigestBundle\ErrorDigestBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

final class ErrorDigestBundleTest extends TestCase
{
    public function testPiiScrubberIsTheDefaultWhenNoScrubberIsConfigured(): void
    {
        self::assertSame(DefaultPiiScrubber::class, (string) $this->load([])->getAlias(PiiScrubber::class));
    }

    public function testPiiScrubberIsTheConfiguredService(): void
    {
        self::assertSame('app.scrubber', (string) $this->load(['scrubber' => 'app.scrubber'])->getAlias(PiiScrubber::class));
    }

    public function testCompileFailsWhenTheConfiguredScrubberDoesNotExist(): void
    {
        $container = $this->built('app.missing');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('error_digest.scrubber: service "app.missing" does not exist.');
        $container->compile();
    }

    public function testCompileFailsWhenTheConfiguredScrubberIsNotAPiiScrubber(): void
    {
        $container = $this->built('app.scrubber');
        $container->register('app.scrubber', \stdClass::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('error_digest.scrubber: service "app.scrubber" (stdClass) does not implement ' . PiiScrubber::class . '.');
        $container->compile();
    }

    public function testCompilesWithAPiiScrubberService(): void
    {
        $container = $this->built('app.scrubber');
        $container->register('app.scrubber', DefaultPiiScrubber::class)->setPublic(true);

        $container->compile();

        self::assertInstanceOf(DefaultPiiScrubber::class, $container->get('app.scrubber'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        (new ErrorDigestBundle())->getContainerExtension()?->load([$config], $container);

        return $container;
    }

    private function built(string $scrubber): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new ErrorDigestBundle())->build($container);
        $container->setParameter('error_digest.scrubber', $scrubber);

        return $container;
    }
}

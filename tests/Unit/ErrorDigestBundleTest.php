<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Unit;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Doctrine\Bundle\MigrationsBundle\DependencyInjection\DoctrineMigrationsExtension;
use Hexis\ErrorDigestBundle\Domain\DefaultPiiScrubber;
use Hexis\ErrorDigestBundle\Domain\PiiScrubber;
use Hexis\ErrorDigestBundle\ErrorDigestBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\MonologBundle\DependencyInjection\MonologExtension;
use Symfony\Bundle\TwigBundle\DependencyInjection\TwigExtension;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

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
     * @return iterable<string, array{string, array<string, mixed>, list<string>, string}>
     */
    public static function hostOverrides(): iterable
    {
        yield 'Monolog handler' => [
            'monolog',
            ['handlers' => ['error_digest' => ['type' => 'service', 'id' => 'app.error_handler']]],
            ['handlers', 'error_digest', 'id'],
            'app.error_handler',
        ];
        yield 'Doctrine mapping' => [
            'doctrine',
            ['orm' => ['mappings' => ['ErrorDigest' => ['dir' => '/app/config/error_digest']]]],
            ['orm', 'entity_managers', 'default', 'mappings', 'ErrorDigest', 'dir'],
            '/app/config/error_digest',
        ];
        yield 'migrations path' => [
            'doctrine_migrations',
            ['migrations_paths' => ['Hexis\ErrorDigestBundle\Migrations' => '/app/migrations/error_digest']],
            ['migrations_paths', 'Hexis\ErrorDigestBundle\Migrations'],
            '/app/migrations/error_digest',
        ];
    }

    /**
     * @param array<string, mixed> $hostConfig
     * @param list<string> $path
     */
    #[DataProvider('hostOverrides')]
    public function testTheHostsConfigWinsOverTheConfigTheBundlePrepends(string $extension, array $hostConfig, array $path, string $expected): void
    {
        $value = $this->processed($this->host([$extension => $hostConfig]), $extension);
        foreach ($path as $key) {
            $value = $value[$key];
        }

        self::assertSame($expected, $value);
    }

    public function testTheHostsTwigPathsAreSearchedBeforeTheBundlesViews(): void
    {
        $container = $this->host(['twig' => ['paths' => ['/app/templates/error_digest' => 'ErrorDigest']]]);

        $paths = array_keys(array_filter(
            $this->processed($container, 'twig')['paths'],
            static fn (?string $namespace): bool => $namespace === 'ErrorDigest',
        ));

        self::assertSame('/app/templates/error_digest', $paths[0]);
        self::assertStringEndsWith('/src/Resources/views', $paths[1]);
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

    /**
     * A host with the bundles the bundle prepends config for, its own config loaded (as from
     * config/packages), and then the bundle's prepend, as the kernel runs them.
     *
     * @param array<string, array<string, mixed>> $hostConfig
     */
    private function host(array $hostConfig): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        foreach ([new MonologExtension(), new DoctrineExtension(), new DoctrineMigrationsExtension(), new TwigExtension()] as $extension) {
            $container->registerExtension($extension);
        }
        $bundleExtension = (new ErrorDigestBundle())->getContainerExtension();
        \assert($bundleExtension instanceof PrependExtensionInterface);
        $container->registerExtension($bundleExtension);

        foreach ($hostConfig as $name => $config) {
            $container->loadFromExtension($name, $config);
        }
        $bundleExtension->prepend($container);

        return $container;
    }

    /**
     * @return array<string, mixed>
     */
    private function processed(ContainerBuilder $container, string $name): array
    {
        $extension = $container->getExtension($name);
        \assert($extension instanceof ConfigurationExtensionInterface);
        $configs = $container->getExtensionConfig($name);

        return (new Processor())->processConfiguration($extension->getConfiguration($configs, $container), $configs);
    }
}

<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Integration;

use Composer\InstalledVersions;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Doctrine\ORM\Configuration as OrmConfiguration;
use Hexis\ErrorDigestBundle\ErrorDigestBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Host application for the kernel tests: the real bundle with the bundles a host has
 * (README "Requirements"), wired the way the README installs it. Doctrine runs on an
 * in-memory SQLite database; the admin UI sits behind HTTP basic auth with in-memory
 * users. Boots on Symfony 6.4, 7.x and 8.x with DoctrineBundle 2 or 3.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const ADMIN = 'admin';
    public const VIEWER = 'viewer';
    public const PASSWORD = 'secret';

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new MonologBundle(),
            new DoctrineBundle(),
            new DoctrineMigrationsBundle(),
            new TwigBundle(),
            new SecurityBundle(),
            new ErrorDigestBundle(),
        ];
    }

    protected function configureContainer(ContainerBuilder $container): void
    {
        $container->loadFromExtension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'csrf_protection' => true,
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'mailer' => ['dsn' => 'null://null'],
            'messenger' => ['enabled' => true],
        ]);

        $container->loadFromExtension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
            'orm' => $this->ormConfig(),
        ]);

        $container->loadFromExtension('twig', ['strict_variables' => true]);

        $container->loadFromExtension('security', [
            'password_hashers' => [InMemoryUser::class => 'plaintext'],
            'providers' => [
                'users' => [
                    'memory' => [
                        'users' => [
                            self::ADMIN => ['password' => self::PASSWORD, 'roles' => ['ROLE_ADMIN']],
                            self::VIEWER => ['password' => self::PASSWORD, 'roles' => ['ROLE_USER']],
                        ],
                    ],
                ],
            ],
            'firewalls' => [
                'main' => ['http_basic' => ['realm' => 'Errors']],
            ],
        ]);

        $container->register(EmailMaskingScrubber::class);

        $container->loadFromExtension('error_digest', [
            'environments' => ['test'],
            'scrubber' => EmailMaskingScrubber::class,
            'ignore' => [
                ['class' => NotFoundHttpException::class],
                ['class' => AccessDeniedException::class],
                ['channel' => 'deprecation', 'level' => 'notice'],
            ],
            'digest' => [
                'recipients' => ['admin@example.com'],
                'from' => 'noreply@example.com',
            ],
            'js' => ['allowed_origins' => ['https://example.com']],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@ErrorDigestBundle/src/Controller/Admin/', 'attribute')->prefix('/_errors');
        $routes->import('@ErrorDigestBundle/src/Controller/Ingest/', 'attribute')->prefix('/_errors/ingest');
    }

    /**
     * Outside the repository: in debug mode Symfony 7.4+ writes config/reference.php
     * into the project dir, and the bundle root has a config/ directory.
     */
    public function getProjectDir(): string
    {
        return $this->varDir();
    }

    public function getCacheDir(): string
    {
        return $this->varDir() . '/cache/' . $this->environment;
    }

    public function getBuildDir(): string
    {
        return $this->varDir() . '/build/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->varDir() . '/log';
    }

    /**
     * DoctrineBundle 3 removed the proxy options: native lazy objects are always on there.
     * DoctrineBundle 2.15+ deprecates generated proxies on PHP 8.4 with ORM 3.4+ in favour
     * of native lazy objects; otherwise DoctrineBundle 2 uses lazy ghost proxies.
     *
     * @return array<string, mixed>
     */
    private function ormConfig(): array
    {
        $doctrineBundle = (string) InstalledVersions::getVersion('doctrine/doctrine-bundle');
        if (version_compare($doctrineBundle, '3.0', '>=')) {
            return [];
        }

        if (\PHP_VERSION_ID >= 80400
            && version_compare($doctrineBundle, '2.15', '>=')
            && method_exists(OrmConfiguration::class, 'enableNativeLazyObjects')
        ) {
            return ['enable_native_lazy_objects' => true];
        }

        return ['auto_generate_proxy_classes' => true, 'enable_lazy_ghost_objects' => true];
    }

    private function varDir(): string
    {
        return sys_get_temp_dir() . '/error-digest-bundle-tests/' . Kernel::VERSION;
    }
}

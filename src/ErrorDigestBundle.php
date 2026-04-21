<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class ErrorDigestBundle extends AbstractBundle
{
    protected string $extensionAlias = 'error_digest';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->enumNode('minimum_level')
                    ->values(['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'])
                    ->defaultValue('warning')
                ->end()
                ->arrayNode('channels')
                    ->info('Monolog channels to capture. null or empty = all channels.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('environments')
                    ->info('Kernel environments in which capture is active.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['prod', 'dev'])
                ->end()
                ->arrayNode('ignore')
                    ->info('Rules that suppress capture. Each rule may specify class, channel, level, or message regex; all present fields must match.')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('class')->defaultNull()->end()
                            ->scalarNode('channel')->defaultNull()->end()
                            ->scalarNode('level')->defaultNull()->end()
                            ->scalarNode('message')->defaultNull()->info('PCRE regex, anchored at caller discretion')->end()
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('dedup')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('fingerprinter')
                            ->info('Service id implementing Hexis\\ErrorDigestBundle\\Domain\\Fingerprinter. null = default.')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('scrubber')
                    ->info('Service id implementing Hexis\\ErrorDigestBundle\\Domain\\PiiScrubber. null = default.')
                    ->defaultNull()
                ->end()
                ->arrayNode('storage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('connection')->defaultValue('default')->end()
                        ->scalarNode('entity_manager')
                            ->info('EntityManager name to attach the ErrorDigest Doctrine mapping to. null = default EM. Match this to the EM that owns the same connection if you run schema:update / migrations per-EM.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('table_prefix')->defaultValue('err_')->end()
                        ->integerNode('occurrence_retention_days')->defaultValue(30)->min(1)->end()
                    ->end()
                ->end()
                ->arrayNode('digest')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('schedule')->defaultValue('0 8 * * *')->end()
                        ->arrayNode('recipients')->scalarPrototype()->end()->defaultValue([])->end()
                        ->scalarNode('from')->defaultNull()->end()
                        ->arrayNode('senders')
                            ->enumPrototype()->values(['mailer', 'notifier'])->end()
                            ->defaultValue(['mailer'])
                        ->end()
                        ->scalarNode('window')->defaultValue('24 hours')->end()
                        ->arrayNode('sections')
                            ->enumPrototype()->values(['new', 'spiking', 'top', 'stale'])->end()
                            ->defaultValue(['new', 'spiking', 'top', 'stale'])
                        ->end()
                        ->integerNode('top_limit')->defaultValue(10)->min(1)->end()
                        ->integerNode('stale_days')->defaultValue(7)->min(1)->end()
                        ->floatNode('spike_multiplier')->defaultValue(3.0)->min(1.0)->end()
                        ->arrayNode('notifier_transports')
                            ->info('Named Notifier chat transports to fan out to. Empty = use the default transport.')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('ui')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('route_prefix')->defaultValue('/_errors')->end()
                        ->scalarNode('role')->defaultValue('ROLE_ADMIN')->end()
                    ->end()
                ->end()
                ->arrayNode('async')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('transport')->defaultNull()->info('Messenger transport name for async flush. null = sync flush on kernel.terminate.')->end()
                    ->end()
                ->end()
                ->arrayNode('rate_limit')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('per_fingerprint_seconds')
                            ->info('Collapse multiple occurrences of the same fingerprint within this window into one occurrence row.')
                            ->defaultValue(1)->min(0)
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/../config/services.php');

        $builder->setParameter('error_digest.enabled', (bool) $config['enabled']);
        $builder->setParameter('error_digest.minimum_level', (string) $config['minimum_level']);
        $builder->setParameter('error_digest.channels', (array) $config['channels']);
        $builder->setParameter('error_digest.environments', (array) $config['environments']);
        $builder->setParameter('error_digest.ignore', (array) $config['ignore']);
        $builder->setParameter('error_digest.dedup.fingerprinter', $config['dedup']['fingerprinter']);
        $builder->setParameter('error_digest.scrubber', $config['scrubber']);
        $builder->setParameter('error_digest.storage.connection', (string) $config['storage']['connection']);
        $builder->setParameter('error_digest.storage.entity_manager', $config['storage']['entity_manager']);
        $builder->setParameter('error_digest.storage.table_prefix', (string) $config['storage']['table_prefix']);
        $builder->setParameter('error_digest.storage.occurrence_retention_days', (int) $config['storage']['occurrence_retention_days']);
        $builder->setParameter('error_digest.digest', $config['digest']);
        $builder->setParameter('error_digest.digest.enabled', (bool) $config['digest']['enabled']);
        $builder->setParameter('error_digest.digest.schedule', (string) $config['digest']['schedule']);
        $builder->setParameter('error_digest.digest.recipients', (array) $config['digest']['recipients']);
        $builder->setParameter('error_digest.digest.from', $config['digest']['from']);
        $builder->setParameter('error_digest.digest.senders', (array) $config['digest']['senders']);
        $builder->setParameter('error_digest.digest.window', (string) $config['digest']['window']);
        $builder->setParameter('error_digest.digest.sections', (array) $config['digest']['sections']);
        $builder->setParameter('error_digest.digest.top_limit', (int) $config['digest']['top_limit']);
        $builder->setParameter('error_digest.digest.stale_days', (int) $config['digest']['stale_days']);
        $builder->setParameter('error_digest.digest.spike_multiplier', (float) $config['digest']['spike_multiplier']);
        $builder->setParameter('error_digest.digest.notifier_transports', (array) $config['digest']['notifier_transports']);
        $builder->setParameter('error_digest.ui', $config['ui']);
        $builder->setParameter('error_digest.ui.enabled', (bool) $config['ui']['enabled']);
        $builder->setParameter('error_digest.ui.route_prefix', (string) $config['ui']['route_prefix']);
        $builder->setParameter('error_digest.ui.role', (string) $config['ui']['role']);
        $builder->setParameter('error_digest.async.transport', $config['async']['transport']);
        $builder->setParameter('error_digest.rate_limit.per_fingerprint_seconds', (int) $config['rate_limit']['per_fingerprint_seconds']);

        $tablePrefix = (string) $config['storage']['table_prefix'];
        $builder->setParameter('error_digest.table.fingerprint', $tablePrefix . 'fingerprint');
        $builder->setParameter('error_digest.table.occurrence', $tablePrefix . 'occurrence');

        $builder->setAlias(
            'error_digest.connection',
            sprintf('doctrine.dbal.%s_connection', $config['storage']['connection']),
        );
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $bundlePath = \dirname(__DIR__);
        $entityManager = $this->resolveConfiguredEntityManager($builder);

        if ($builder->hasExtension('doctrine')) {
            $mapping = [
                'ErrorDigest' => [
                    'type' => 'xml',
                    'dir' => $bundlePath . '/src/Resources/config/doctrine',
                    'prefix' => 'Hexis\\ErrorDigestBundle\\Entity',
                    'alias' => 'ErrorDigest',
                    'is_bundle' => false,
                ],
            ];

            $ormConfig = $entityManager === null
                ? ['mappings' => $mapping]
                : ['entity_managers' => [$entityManager => ['mappings' => $mapping]]];

            $container->extension('doctrine', ['orm' => $ormConfig]);
        }

        if ($builder->hasExtension('doctrine_migrations')) {
            $container->extension('doctrine_migrations', [
                'migrations_paths' => [
                    'Hexis\\ErrorDigestBundle\\Migrations' => $bundlePath . '/src/Resources/migrations',
                ],
            ]);
        }

        if ($builder->hasExtension('monolog')) {
            $container->extension('monolog', [
                'handlers' => [
                    'error_digest' => [
                        'type' => 'service',
                        'id' => \Hexis\ErrorDigestBundle\Monolog\ErrorDigestHandler::class,
                    ],
                ],
            ]);
        }

        if ($builder->hasExtension('twig')) {
            $container->extension('twig', [
                'paths' => [
                    $bundlePath . '/src/Resources/views' => 'ErrorDigest',
                ],
            ]);
        }
    }

    /**
     * Read the host's raw `error_digest.storage.entity_manager` config, if any.
     * prependExtension runs before loadExtension, so the bundle's config tree
     * isn't processed yet — scan the raw configs and take the last-wins value.
     */
    private function resolveConfiguredEntityManager(ContainerBuilder $builder): ?string
    {
        foreach (array_reverse($builder->getExtensionConfig('error_digest')) as $config) {
            if (isset($config['storage']['entity_manager'])
                && $config['storage']['entity_manager'] !== null
                && $config['storage']['entity_manager'] !== ''
            ) {
                return (string) $config['storage']['entity_manager'];
            }
        }

        return null;
    }
}

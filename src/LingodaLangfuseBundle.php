<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle;

use Lingoda\LangfuseBundle\PhpStan\Types;
use Lingoda\LangfuseBundle\Storage\StorageFactory;
use Lingoda\LangfuseBundle\Tracing\AsyncTraceFlusher;
use Lingoda\LangfuseBundle\Tracing\TraceFlusherInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * @phpstan-import-type BundleConfig from Types
 * @phpstan-import-type TracingConfig from Types
 * @phpstan-import-type PromptsConfig from Types
 */
class LingodaLangfuseBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $definition->rootNode();

        /** @phpstan-ignore-next-line */
        $rootNode
            ->children()
                // Connection settings
                ->arrayNode('connection')
                    ->children()
                        ->scalarNode('public_key')->isRequired()->end()
                        ->scalarNode('secret_key')->isRequired()->end()
                        ->scalarNode('host')->defaultValue('https://cloud.langfuse.com')->end()
                        ->integerNode('timeout')->defaultValue(30)->end()
                        ->arrayNode('retry')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('max_attempts')->defaultValue(3)->end()
                                ->integerNode('delay')->defaultValue(1000)->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()

                // Tracing settings
                ->arrayNode('tracing')
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->floatNode('sampling_rate')->defaultValue(1.0)->end()
                        ->arrayNode('async_flush')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultFalse()->end()
                                ->scalarNode('message_bus')->defaultValue('messenger.default_bus')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()

                // Prompts settings
                ->arrayNode('prompts')
                    ->children()
                        ->arrayNode('caching')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultFalse()->end()
                                ->integerNode('ttl')->defaultValue(3600)->end()
                                ->scalarNode('service')->defaultValue('cache.app')->end()
                            ->end()
                        ->end()
                        ->arrayNode('fallback')
                            ->canBeDisabled()
                            ->children()
                                ->arrayNode('storage')
                                    ->children()
                                        ->scalarNode('path')
                                            ->info('Directory path for simple file-based storage')
                                        ->end()
                                        ->scalarNode('service')
                                            ->info('Service ID for Flysystem-based storage (overrides path if set)')
                                        ->end()
                                    ->end()
                                    ->validate()
                                        ->ifTrue(fn ($v) => !empty($v['path']) && !empty($v['service']))
                                        ->thenInvalid('Cannot specify both path and service for fallback storage')
                                    ->end()
                                    ->validate()
                                        ->ifTrue(fn ($v) => empty($v['path']) && empty($v['service']))
                                        ->then(function ($v) {
                                            // Set default path if neither is specified
                                            $v['path'] = '%kernel.project_dir%/var/prompts';
                                            return $v;
                                        })
                                    ->end()
                                ->end()
                            ->end()
                            ->validate()
                                ->ifTrue(function ($v) {
                                    // If fallback is enabled but no storage configuration provided
                                    return $v['enabled'] && (!isset($v['storage']) || (empty($v['storage']['path']) && empty($v['storage']['service'])));
                                })
                                ->thenInvalid('When fallback is enabled, either storage.path or storage.service must be specified')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * @param BundleConfig $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        // Ensure safe defaults for all parameters
        $config = $this->ensureDefaults($config);

        $this->bindParameters($builder, $this->extensionAlias, $config);

        // Handle prompts fallback storage configuration
        $promptsConfig = $config['prompts'] ?? [];
        $fallbackConfig = $promptsConfig['fallback'] ?? [];
        if ($fallbackConfig['enabled']) {
            $storageConfig = $fallbackConfig['storage'] ?? [];

            // If a service is specified, modify the factory to inject the service directly
            if (!empty($storageConfig['service'])) {
                $serviceId = $storageConfig['service'];
                $builder->getDefinition(StorageFactory::class)
                    ->setArguments([
                        new Reference($serviceId) // Inject the filesystem service directly
                    ])
                ;
            }
        }

        $tracingConfig = $config['tracing'];
        if ($tracingConfig['async_flush']['enabled']) {
            $messageBus = $tracingConfig['async_flush']['message_bus'];
            $builder->register(AsyncTraceFlusher::class, AsyncTraceFlusher::class)
                ->setArguments([
                    new Reference($messageBus),
                    new Reference('monolog.logger.langfuse', ContainerBuilder::NULL_ON_INVALID_REFERENCE)
                ])
                ->addTag('monolog.logger', ['channel' => 'langfuse'])
            ;

            $builder->setAlias(TraceFlusherInterface::class, AsyncTraceFlusher::class);
        }
    }

    /**
     * @param array<string, mixed>|scalar|null $config
     */
    private function bindParameters(ContainerBuilder $container, string $alias, array|string|int|float|bool|null $config): void
    {
        if (\is_array($config) && !isset($config[0])) {
            // Always set the parameter even for empty arrays
            $container->setParameter($alias, $config);

            foreach ($config as $key => $value) {
                /** @var array<string, mixed>|scalar|null $value */
                $this->bindParameters($container, $alias . '.' . $key, $value);
            }
        } else {
            $container->setParameter($alias, $config);
        }
    }

    /**
     * @param BundleConfig $config
     *
     * @return BundleConfig
     */
    private function ensureDefaults(array $config): array
    {
        // Ensure tracing defaults exist
        $tracingConfig = $config['tracing'] ?? [];
        if (!isset($tracingConfig['async_flush'])) {
            $tracingConfig['async_flush'] = [
                'enabled' => false,
                'message_bus' => 'messenger.bus.default',
            ];
        }
        $config['tracing'] = $tracingConfig;

        // Ensure prompts defaults exist
        $promptsConfig = $config['prompts'] ?? [];
        if (!isset($promptsConfig['caching'])) {
            $promptsConfig['caching'] = [
                'enabled' => false,
                'service' => 'cache.app',
                'ttl' => 3600,
            ];
        }

        if (!isset($promptsConfig['fallback'])) {
            $promptsConfig['fallback'] = ['enabled' => false];
        }
        $config['prompts'] = $promptsConfig;

        return $config;
    }
}

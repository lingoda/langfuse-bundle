<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit;

use Lingoda\LangfuseBundle\LingodaLangfuseBundle;
use Lingoda\LangfuseBundle\Tracing\AsyncTraceFlusher;
use Lingoda\LangfuseBundle\Tracing\TraceFlusherInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Loader\DefinitionFileLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;

final class LingodaLangfuseBundleTest extends TestCase
{
    private LingodaLangfuseBundle $bundle;

    protected function setUp(): void
    {
        $this->bundle = new LingodaLangfuseBundle();
    }

    public function testConfigureBuildsValidConfigurationTree(): void
    {
        // Test that the configure method actually builds a valid configuration tree
        $treeBuilder = new TreeBuilder('lingoda_langfuse');

        // Create a minimal DefinitionConfigurator with mocked loader
        $mockLoader = $this->createMock(DefinitionFileLoader::class);
        $configurator = new DefinitionConfigurator(
            $treeBuilder,
            $mockLoader,
            __DIR__,
            'test.php'
        );

        $this->bundle->configure($configurator);
        $tree = $treeBuilder->buildTree();
        self::assertEquals('lingoda_langfuse', $tree->getName());

        // Test with minimal valid configuration - just validate it can be finalized
        $config = [
            'connection' => [
                'public_key' => 'test-key',
                'secret_key' => 'test-secret'
            ]
        ];
        $processedConfig = $tree->finalize($tree->normalize($config));

        // Verify some key configuration was processed
        self::assertEquals('test-key', $processedConfig['connection']['public_key']);
        self::assertEquals('test-secret', $processedConfig['connection']['secret_key']);
        self::assertEquals('https://cloud.langfuse.com', $processedConfig['connection']['host']);
        self::assertEquals(30, $processedConfig['connection']['timeout']);

        // Test with full configuration to exercise more paths
        $fullConfig = [
            'connection' => [
                'public_key' => 'test-key',
                'secret_key' => 'test-secret',
                'host' => 'https://custom.langfuse.com',
                'timeout' => 60,
                'retry' => [
                    'max_attempts' => 5,
                    'delay' => 2000
                ]
            ],
            'tracing' => [
                'enabled' => true,
                'sampling_rate' => 0.8,
                'async_flush' => [
                    'enabled' => false,
                    'message_bus' => 'custom.bus'
                ]
            ],
            'prompts' => [
                'caching' => [
                    'enabled' => true,
                    'ttl' => 7200,
                    'service' => 'cache.redis'
                ],
                'fallback' => [
                    'enabled' => true,
                    'storage' => [
                        'path' => '/custom/path'
                    ]
                ]
            ]
        ];

        $fullProcessedConfig = $tree->finalize($tree->normalize($fullConfig));

        // Verify complex configuration processing
        self::assertEquals('https://custom.langfuse.com', $fullProcessedConfig['connection']['host']);
        self::assertEquals(60, $fullProcessedConfig['connection']['timeout']);
        self::assertEquals(5, $fullProcessedConfig['connection']['retry']['max_attempts']);
        self::assertEquals(0.8, $fullProcessedConfig['tracing']['sampling_rate']);
        self::assertTrue($fullProcessedConfig['prompts']['caching']['enabled']);
        self::assertTrue($fullProcessedConfig['prompts']['fallback']['enabled']);
        self::assertEquals('/custom/path', $fullProcessedConfig['prompts']['fallback']['storage']['path']);
    }

    public function testConfigureValidatesConfiguration(): void
    {
        // Create another tree to test validation
        $treeBuilder = new TreeBuilder('lingoda_langfuse');
        $mockLoader = $this->createMock(DefinitionFileLoader::class);
        $configurator = new DefinitionConfigurator(
            $treeBuilder,
            $mockLoader,
            __DIR__,
            'test.php'
        );

        $this->bundle->configure($configurator);
        $tree = $treeBuilder->buildTree();

        // Test fallback validation - both path and service specified should fail
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Cannot specify both path and service for fallback storage');

        $invalidConfig = [
            'connection' => [
                'public_key' => 'test-key',
                'secret_key' => 'test-secret'
            ],
            'prompts' => [
                'fallback' => [
                    'enabled' => true,
                    'storage' => [
                        'path' => '/some/path',
                        'service' => 'some.service'
                    ]
                ]
            ]
        ];

        $tree->finalize($tree->normalize($invalidConfig));
    }

    public function testConfigureMethodStructure(): void
    {
        // Test that the configure method has the expected structure by checking its code
        $reflection = new \ReflectionClass($this->bundle);
        $method = $reflection->getMethod('configure');

        // Get the source code to verify it contains the expected configuration elements
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $filename = $reflection->getFileName();

        $source = file($filename);
        $methodSource = implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));

        // Verify the method contains expected configuration sections
        self::assertStringContainsString('connection', $methodSource);
        self::assertStringContainsString('public_key', $methodSource);
        self::assertStringContainsString('secret_key', $methodSource);
        self::assertStringContainsString('tracing', $methodSource);
        self::assertStringContainsString('prompts', $methodSource);
        self::assertStringContainsString('caching', $methodSource);
        self::assertStringContainsString('fallback', $methodSource);
        self::assertStringContainsString('async_flush', $methodSource);
        self::assertStringContainsString('isRequired', $methodSource);
        self::assertStringContainsString('defaultValue', $methodSource);
        self::assertStringContainsString('defaultTrue', $methodSource);
        self::assertStringContainsString('defaultFalse', $methodSource);

        // This at least ensures the configure method contains the expected configuration logic
        self::assertGreaterThan(50, mb_strlen($methodSource), 'Configure method should contain substantial configuration logic');
    }

    public function testLoadExtensionWithMinimalConfig(): void
    {
        $config = [
            'connection' => [
                'public_key' => 'test-public-key',
                'secret_key' => 'test-secret-key',
            ]
        ];

        $mockContainer = $this->createMock(ContainerConfigurator::class);
        $mockBuilder = $this->createMock(ContainerBuilder::class);

        $mockContainer
            ->expects(self::once())
            ->method('import')
            ->with('../config/services.php')
        ;

        $mockBuilder
            ->expects(self::atLeastOnce())
            ->method('setParameter')
        ;

        $this->bundle->loadExtension($config, $mockContainer, $mockBuilder);
    }

    public function testLoadExtensionWithAsyncFlushEnabled(): void
    {
        $config = [
            'connection' => [
                'public_key' => 'test-public-key',
                'secret_key' => 'test-secret-key',
            ],
            'tracing' => [
                'async_flush' => [
                    'enabled' => true,
                    'message_bus' => 'messenger.bus.custom'
                ]
            ]
        ];

        $mockContainer = $this->createMock(ContainerConfigurator::class);
        $mockBuilder = $this->createMock(ContainerBuilder::class);
        $mockDefinition = $this->createMock(Definition::class);

        $mockContainer
            ->expects(self::once())
            ->method('import')
            ->with('../config/services.php')
        ;

        $mockBuilder
            ->expects(self::once())
            ->method('register')
            ->with(AsyncTraceFlusher::class, AsyncTraceFlusher::class)
            ->willReturn($mockDefinition)
        ;

        $mockDefinition
            ->expects(self::once())
            ->method('setArguments')
            ->with([
                new Reference('messenger.bus.custom'),
                new Reference('monolog.logger.langfuse', ContainerBuilder::NULL_ON_INVALID_REFERENCE)
            ])
            ->willReturnSelf()
        ;

        $mockDefinition
            ->expects(self::once())
            ->method('addTag')
            ->with('monolog.logger', ['channel' => 'langfuse'])
            ->willReturnSelf()
        ;

        $mockBuilder
            ->expects(self::once())
            ->method('setAlias')
            ->with(TraceFlusherInterface::class, AsyncTraceFlusher::class)
        ;

        $this->bundle->loadExtension($config, $mockContainer, $mockBuilder);
    }

    public function testLoadExtensionWithAsyncFlushDisabled(): void
    {
        $config = [
            'connection' => [
                'public_key' => 'test-public-key',
                'secret_key' => 'test-secret-key',
            ],
            'tracing' => [
                'async_flush' => [
                    'enabled' => false
                ]
            ]
        ];

        $mockContainer = $this->createMock(ContainerConfigurator::class);
        $mockBuilder = $this->createMock(ContainerBuilder::class);

        $mockBuilder
            ->expects(self::never())
            ->method('register')
        ;

        $mockBuilder
            ->expects(self::never())
            ->method('setAlias')
        ;

        $this->bundle->loadExtension($config, $mockContainer, $mockBuilder);
    }

    public function testEnsureDefaultsWithEmptyConfig(): void
    {
        $reflection = new \ReflectionClass($this->bundle);
        $method = $reflection->getMethod('ensureDefaults');

        $result = $method->invoke($this->bundle, []);

        self::assertArrayHasKey('tracing', $result);
        self::assertArrayHasKey('async_flush', $result['tracing']);
        self::assertFalse($result['tracing']['async_flush']['enabled']);
        self::assertEquals('messenger.bus.default', $result['tracing']['async_flush']['message_bus']);

        self::assertArrayHasKey('prompts', $result);
        self::assertArrayHasKey('caching', $result['prompts']);
        self::assertFalse($result['prompts']['caching']['enabled']);
        self::assertEquals('cache.app', $result['prompts']['caching']['service']);
        self::assertEquals(3600, $result['prompts']['caching']['ttl']);

        self::assertArrayHasKey('fallback', $result['prompts']);
        self::assertFalse($result['prompts']['fallback']['enabled']);
    }

    public function testEnsureDefaultsWithExistingConfig(): void
    {
        $config = [
            'tracing' => [
                'async_flush' => [
                    'enabled' => true,
                    'message_bus' => 'custom.bus'
                ]
            ],
            'prompts' => [
                'caching' => [
                    'enabled' => true,
                    'ttl' => 7200
                ]
            ]
        ];

        $reflection = new \ReflectionClass($this->bundle);
        $method = $reflection->getMethod('ensureDefaults');

        $result = $method->invoke($this->bundle, $config);

        // Should preserve existing values
        self::assertTrue($result['tracing']['async_flush']['enabled']);
        self::assertEquals('custom.bus', $result['tracing']['async_flush']['message_bus']);
        self::assertTrue($result['prompts']['caching']['enabled']);
        self::assertEquals(7200, $result['prompts']['caching']['ttl']);

        // Should add missing fallback
        self::assertArrayHasKey('fallback', $result['prompts']);
        self::assertFalse($result['prompts']['fallback']['enabled']);
    }

    public function testEnsureDefaultsWithPartialConfig(): void
    {
        $config = [
            'prompts' => [
                'fallback' => [
                    'enabled' => true,
                    'storage' => ['path' => '/custom/path']
                ]
            ]
        ];

        $reflection = new \ReflectionClass($this->bundle);
        $method = $reflection->getMethod('ensureDefaults');

        $result = $method->invoke($this->bundle, $config);

        // Should add missing tracing defaults
        self::assertArrayHasKey('tracing', $result);
        self::assertArrayHasKey('async_flush', $result['tracing']);
        self::assertFalse($result['tracing']['async_flush']['enabled']);

        // Should add missing caching defaults
        self::assertArrayHasKey('caching', $result['prompts']);
        self::assertFalse($result['prompts']['caching']['enabled']);

        // Should preserve existing fallback
        self::assertTrue($result['prompts']['fallback']['enabled']);
        self::assertEquals('/custom/path', $result['prompts']['fallback']['storage']['path']);
    }

    public function testBindParametersWithSimpleArray(): void
    {
        $config = [
            'host' => 'https://example.com',
            'timeout' => 30,
            'enabled' => true
        ];

        $mockBuilder = $this->createMock(ContainerBuilder::class);

        $expectedCalls = [
            ['test_alias', $config],
            ['test_alias.host', 'https://example.com'],
            ['test_alias.timeout', 30],
            ['test_alias.enabled', true]
        ];

        $mockBuilder
            ->expects(self::exactly(4))
            ->method('setParameter')
            ->with(
                self::callback(function ($alias) use (&$expectedCalls) {
                    static $callIndex = 0;
                    $expected = $expectedCalls[$callIndex];
                    $callIndex++;
                    return $alias === $expected[0];
                }),
                self::callback(function ($value) use (&$expectedCalls) {
                    static $callIndex = 0;
                    $expected = $expectedCalls[$callIndex];
                    $callIndex++;
                    return $value === $expected[1];
                })
            )
        ;

        $reflection = new \ReflectionClass($this->bundle);
        $method = $reflection->getMethod('bindParameters');

        $method->invoke($this->bundle, $mockBuilder, 'test_alias', $config);
    }

    public function testBindParametersWithNestedArray(): void
    {
        $config = [
            'connection' => [
                'host' => 'https://example.com',
                'credentials' => [
                    'public_key' => 'test-key',
                    'secret_key' => 'test-secret'
                ]
            ]
        ];

        $mockBuilder = $this->createMock(ContainerBuilder::class);

        // Expected calls:
        // 1. test -> full config
        // 2. test.connection -> connection array
        // 3. test.connection.host -> host value
        // 4. test.connection.credentials -> credentials array
        // 5. test.connection.credentials.public_key -> public_key value
        // 6. test.connection.credentials.secret_key -> secret_key value
        $mockBuilder
            ->expects(self::exactly(6))
            ->method('setParameter')
        ;

        $reflection = new \ReflectionClass($this->bundle);
        $method = $reflection->getMethod('bindParameters');

        $method->invoke($this->bundle, $mockBuilder, 'test', $config);
    }

    public function testBindParametersWithNonArrayValue(): void
    {
        $mockBuilder = $this->createMock(ContainerBuilder::class);

        $mockBuilder
            ->expects(self::once())
            ->method('setParameter')
            ->with('test.scalar', 'simple-value')
        ;

        $reflection = new \ReflectionClass($this->bundle);
        $method = $reflection->getMethod('bindParameters');

        $method->invoke($this->bundle, $mockBuilder, 'test.scalar', 'simple-value');
    }

    public function testBindParametersWithIndexedArray(): void
    {
        $config = ['value1', 'value2', 'value3'];

        $mockBuilder = $this->createMock(ContainerBuilder::class);

        $mockBuilder
            ->expects(self::once())
            ->method('setParameter')
            ->with('test.indexed', $config)
        ;

        $reflection = new \ReflectionClass($this->bundle);
        $method = $reflection->getMethod('bindParameters');

        $method->invoke($this->bundle, $mockBuilder, 'test.indexed', $config);
    }

    public function testBindParametersWithEmptyArray(): void
    {
        $config = [];

        $mockBuilder = $this->createMock(ContainerBuilder::class);

        $mockBuilder
            ->expects(self::once())
            ->method('setParameter')
            ->with('test.empty', $config)
        ;

        $reflection = new \ReflectionClass($this->bundle);
        $method = $reflection->getMethod('bindParameters');

        $method->invoke($this->bundle, $mockBuilder, 'test.empty', $config);
    }

    public function testBundleNameFollowsConventions(): void
    {
        // Test that the bundle name follows Symfony conventions
        $className = get_class($this->bundle);
        self::assertStringEndsWith('Bundle', $className);
        self::assertStringContainsString('Langfuse', $className);
    }

    public function testLoadExtensionHandlesComplexConfig(): void
    {
        $config = [
            'connection' => [
                'public_key' => 'test-key',
                'secret_key' => 'test-secret',
                'host' => 'https://custom.langfuse.com',
                'timeout' => 60,
                'retry' => [
                    'max_attempts' => 5,
                    'delay' => 2000
                ]
            ],
            'tracing' => [
                'enabled' => true,
                'sampling_rate' => 0.8,
                'async_flush' => [
                    'enabled' => false
                ]
            ],
            'prompts' => [
                'caching' => [
                    'enabled' => true,
                    'ttl' => 7200,
                    'service' => 'cache.redis'
                ],
                'fallback' => [
                    'enabled' => true,
                    'storage' => [
                        'path' => '/custom/prompts'
                    ]
                ]
            ]
        ];

        $mockContainer = $this->createMock(ContainerConfigurator::class);
        $mockBuilder = $this->createMock(ContainerBuilder::class);

        $mockContainer
            ->expects(self::once())
            ->method('import')
        ;

        // Should bind all nested parameters
        $mockBuilder
            ->expects(self::atLeastOnce())
            ->method('setParameter')
        ;

        $this->bundle->loadExtension($config, $mockContainer, $mockBuilder);
    }
}

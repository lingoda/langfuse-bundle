<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Storage;

use Lingoda\LangfuseBundle\Storage\PromptStorageInterface;
use Lingoda\LangfuseBundle\Storage\PromptStorageRegistry;
use PHPUnit\Framework\TestCase;

final class PromptStorageRegistryTest extends TestCase
{
    public function testEmptyRegistry(): void
    {
        $registry = new PromptStorageRegistry([]);

        self::assertNull($registry->load('test'));
        self::assertFalse($registry->save('test', []));
        self::assertFalse($registry->exists('test'));
        self::assertFalse($registry->delete('test'));
        self::assertEquals([], $registry->list());
        self::assertFalse($registry->isAvailable());
        self::assertFalse($registry->supports('any config'));
    }

    public function testRegistryWithNullConfig(): void
    {
        $mockStorage = $this->createMock(PromptStorageInterface::class);
        $registry = new PromptStorageRegistry([$mockStorage], null);

        self::assertNull($registry->load('test'));
        self::assertFalse($registry->save('test', []));
        self::assertFalse($registry->exists('test'));
        self::assertFalse($registry->delete('test'));
        self::assertEquals([], $registry->list());
        self::assertFalse($registry->isAvailable());
        self::assertFalse($registry->supports('any config'));
    }

    public function testRegistryWithMatchingStorage(): void
    {
        $config = '/tmp/test';
        $promptData = ['name' => 'test', 'content' => 'Hello'];

        $mockStorage = $this->createMock(PromptStorageInterface::class);
        $mockStorage->method('supports')->with($config)->willReturn(true);
        $mockStorage->method('load')->with('test', 1, 'prod')->willReturn($promptData);
        $mockStorage->method('save')->with('test', $promptData, 1, 'prod')->willReturn(true);
        $mockStorage->method('exists')->with('test', 1, 'prod')->willReturn(true);
        $mockStorage->method('delete')->with('test', 1, 'prod')->willReturn(true);
        $mockStorage->method('list')->willReturn(['test', 'example']);
        $mockStorage->method('isAvailable')->willReturn(true);

        $registry = new PromptStorageRegistry([$mockStorage], $config);

        self::assertEquals($promptData, $registry->load('test', 1, 'prod'));
        self::assertTrue($registry->save('test', $promptData, 1, 'prod'));
        self::assertTrue($registry->exists('test', 1, 'prod'));
        self::assertTrue($registry->delete('test', 1, 'prod'));
        self::assertEquals(['test', 'example'], $registry->list());
        self::assertTrue($registry->isAvailable());
        self::assertTrue($registry->supports($config));
    }

    public function testRegistryWithMultipleStorages(): void
    {
        $pathConfig = '/tmp/test';
        $serviceConfig = 'filesystem.service';

        $mockPathStorage = $this->createMock(PromptStorageInterface::class);
        $mockPathStorage->method('supports')
            ->willReturnCallback(fn ($config) => is_string($config) && str_starts_with($config, '/'))
        ;

        $mockServiceStorage = $this->createMock(PromptStorageInterface::class);
        $mockServiceStorage->method('supports')
            ->willReturnCallback(fn ($config) => is_string($config) && str_contains($config, '.service'))
        ;

        $registry = new PromptStorageRegistry([$mockPathStorage, $mockServiceStorage], $pathConfig);

        // Should select path storage for path config
        self::assertTrue($registry->supports($pathConfig));

        // Should select service storage for service config
        self::assertTrue($registry->supports($serviceConfig));

        // Should not support unsupported config
        self::assertFalse($registry->supports(['array' => 'config']));
    }

    public function testRegistrySelectsFirstMatchingStorage(): void
    {
        $config = 'test-config';
        $promptData = ['name' => 'test'];

        $mockStorage1 = $this->createMock(PromptStorageInterface::class);
        $mockStorage1->method('supports')->with($config)->willReturn(true);
        $mockStorage1->method('load')->with('test')->willReturn($promptData);

        $mockStorage2 = $this->createMock(PromptStorageInterface::class);
        $mockStorage2->method('supports')->with($config)->willReturn(true);
        $mockStorage2->expects(self::never())->method('load'); // Should not be called

        $registry = new PromptStorageRegistry([$mockStorage1, $mockStorage2], $config);

        $result = $registry->load('test');
        self::assertEquals($promptData, $result);
    }

    public function testRegistryWithUnsupportedConfig(): void
    {
        $config = 'unsupported-config';

        $mockStorage = $this->createMock(PromptStorageInterface::class);
        $mockStorage->method('supports')->with($config)->willReturn(false);

        $registry = new PromptStorageRegistry([$mockStorage], $config);

        self::assertNull($registry->load('test'));
        self::assertFalse($registry->save('test', []));
        self::assertFalse($registry->exists('test'));
        self::assertFalse($registry->delete('test'));
        self::assertEquals([], $registry->list());
        self::assertFalse($registry->isAvailable());
        self::assertFalse($registry->supports($config));
    }

    public function testRegistryHandlesNullStorageResults(): void
    {
        $config = 'test-config';

        $mockStorage = $this->createMock(PromptStorageInterface::class);
        $mockStorage->method('supports')->with($config)->willReturn(true);
        $mockStorage->method('load')->willReturn(null);
        // These methods have proper return types, so test with valid values that simulate null behavior
        $mockStorage->method('save')->willReturn(false);
        $mockStorage->method('exists')->willReturn(false);
        $mockStorage->method('delete')->willReturn(false);
        $mockStorage->method('list')->willReturn([]);
        $mockStorage->method('isAvailable')->willReturn(false);

        $registry = new PromptStorageRegistry([$mockStorage], $config);

        self::assertNull($registry->load('test'));
        self::assertFalse($registry->save('test', []));
        self::assertFalse($registry->exists('test'));
        self::assertFalse($registry->delete('test'));
        self::assertEquals([], $registry->list());
        self::assertFalse($registry->isAvailable());
    }

    public function testSupportsMethodWithDifferentConfig(): void
    {
        $registryConfig = '/tmp/registry';
        $testConfig = '/tmp/test';

        $mockStorage = $this->createMock(PromptStorageInterface::class);
        $mockStorage->method('supports')
            ->willReturnCallback(fn ($config) => str_starts_with($config, '/tmp/'))
        ;

        $registry = new PromptStorageRegistry([$mockStorage], $registryConfig);

        // Registry supports its own config
        self::assertTrue($registry->supports($registryConfig));

        // Registry also supports test config if storage supports it
        self::assertTrue($registry->supports($testConfig));

        // Registry doesn't support incompatible config
        self::assertFalse($registry->supports('incompatible'));
    }

    public function testRegistryWithComplexStorageInteractions(): void
    {
        $config = 'complex-config';

        $mockStorage = $this->createMock(PromptStorageInterface::class);
        $mockStorage->method('supports')->with($config)->willReturn(true);

        // Set up expectations for individual method calls
        $mockStorage->expects(self::exactly(2))->method('load')
            ->willReturnCallback(function ($name, $version, $label) {
                if ($name === 'prompt1' && $version === null && $label === null) {
                    return ['name' => 'prompt1'];
                }
                if ($name === 'prompt2' && $version === 5 && $label === 'production') {
                    return null;
                }
                return null;
            })
        ;

        $mockStorage->expects(self::once())->method('save')
            ->with('new-prompt', ['content' => 'test'], 1, 'staging')
            ->willReturn(true)
        ;

        $mockStorage->expects(self::once())->method('exists')
            ->with('existing-prompt')
            ->willReturn(true)
        ;

        $mockStorage->expects(self::once())->method('delete')
            ->with('old-prompt')
            ->willReturn(true)
        ;

        $mockStorage->expects(self::once())->method('list')
            ->willReturn(['prompt1', 'prompt2', 'prompt3'])
        ;

        $mockStorage->expects(self::once())->method('isAvailable')
            ->willReturn(true)
        ;

        $registry = new PromptStorageRegistry([$mockStorage], $config);

        // Execute all operations
        self::assertEquals(['name' => 'prompt1'], $registry->load('prompt1'));
        self::assertNull($registry->load('prompt2', 5, 'production'));
        self::assertTrue($registry->save('new-prompt', ['content' => 'test'], 1, 'staging'));
        self::assertTrue($registry->exists('existing-prompt'));
        self::assertTrue($registry->delete('old-prompt'));
        self::assertEquals(['prompt1', 'prompt2', 'prompt3'], $registry->list());
        self::assertTrue($registry->isAvailable());
    }
}

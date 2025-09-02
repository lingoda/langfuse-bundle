<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Storage;

use League\Flysystem\FilesystemOperator;
use Lingoda\LangfuseBundle\Storage\StorageFactory;
use PHPUnit\Framework\TestCase;

final class StorageFactoryTest extends TestCase
{
    public function testCreateWithNullConfig(): void
    {
        $factory = new StorageFactory();
        $registry = $factory->create(null);
        self::assertFalse($registry->isAvailable());
    }

    public function testCreateWithEmptyConfig(): void
    {
        $factory = new StorageFactory();
        $registry = $factory->create([]);
        self::assertFalse($registry->isAvailable());
    }

    public function testCreateWithEmptyStorageConfig(): void
    {
        $factory = new StorageFactory();
        $config = ['storage' => []];
        $registry = $factory->create($config);
        self::assertFalse($registry->isAvailable());
    }

    public function testCreateWithInjectedFilesystemService(): void
    {
        if (!interface_exists(FilesystemOperator::class)) {
            self::markTestSkipped('Flysystem is not available');
        }

        $mockFilesystem = $this->createMock(FilesystemOperator::class);
        $factory = new StorageFactory($mockFilesystem);

        $config = [
            'storage' => [
                'service' => 'some.service.id'
            ]
        ];

        $registry = $factory->create($config);
        self::assertTrue($registry->supports($mockFilesystem));
    }

    public function testCreateWithPathStorage(): void
    {
        $factory = new StorageFactory();
        $config = [
            'storage' => [
                'path' => '/tmp/prompts'
            ]
        ];

        $registry = $factory->create($config);
        self::assertTrue($registry->supports('/tmp/prompts'));
    }

    public function testCreateWithEmptyPath(): void
    {
        $factory = new StorageFactory();
        $config = [
            'storage' => [
                'path' => ''
            ]
        ];

        $registry = $factory->create($config);
        self::assertFalse($registry->isAvailable());
    }

    public function testCreateWithNullPath(): void
    {
        $factory = new StorageFactory();
        $config = [
            'storage' => [
                'path' => null
            ]
        ];

        $registry = $factory->create($config);
        self::assertFalse($registry->isAvailable());
    }

    public function testInjectedServiceTakesPriorityOverPath(): void
    {
        if (!interface_exists(FilesystemOperator::class)) {
            self::markTestSkipped('Flysystem is not available');
        }

        $mockFilesystem = $this->createMock(FilesystemOperator::class);
        $factory = new StorageFactory($mockFilesystem);

        $config = [
            'storage' => [
                'service' => 'flysystem.storage',
                'path' => '/tmp/prompts' // This should be ignored
            ]
        ];

        $registry = $factory->create($config);

        // Should use injected Flysystem, not path storage
        self::assertTrue($registry->supports($mockFilesystem));
        self::assertFalse($registry->supports('/tmp/prompts'));
    }

    public function testCreateWithComplexConfig(): void
    {
        $factory = new StorageFactory();
        $config = [
            'other_key' => 'ignored',
            'storage' => [
                'path' => '/var/cache/prompts',
                'other_storage_key' => 'ignored'
            ],
            'another_key' => 'also_ignored'
        ];

        $registry = $factory->create($config);
        self::assertTrue($registry->supports('/var/cache/prompts'));
    }

    public function testCreateWithMissingStorageKey(): void
    {
        $factory = new StorageFactory();
        $config = [
            'other_settings' => ['key' => 'value'],
            'not_storage' => ['path' => '/tmp']
        ];

        $registry = $factory->create($config);
        self::assertFalse($registry->isAvailable());
    }

    public function testCreateWithNullFilesystemService(): void
    {
        $factory = new StorageFactory(null);
        $config = [
            'storage' => [
                'path' => '/custom/path'
            ]
        ];

        $registry = $factory->create($config);
        self::assertTrue($registry->supports('/custom/path'));
    }

    public function testCreateWithFilesystemServiceIgnoresPath(): void
    {
        if (!interface_exists(FilesystemOperator::class)) {
            self::markTestSkipped('Flysystem is not available');
        }

        $mockFilesystem = $this->createMock(FilesystemOperator::class);
        $factory = new StorageFactory($mockFilesystem);

        $config = [
            'storage' => [
                'path' => '/should/be/ignored'
            ]
        ];

        $registry = $factory->create($config);

        // Should use injected service, path should be ignored
        self::assertTrue($registry->supports($mockFilesystem));
        self::assertFalse($registry->supports('/should/be/ignored'));
    }

    public function testCreateWithNonStringPath(): void
    {
        $factory = new StorageFactory();
        $config = [
            'storage' => [
                'path' => 12345 // Invalid non-string path
            ]
        ];

        $registry = $factory->create($config);
        self::assertFalse($registry->isAvailable());
    }

    public function testCreateWithEmptyFallbackConfig(): void
    {
        $factory = new StorageFactory();
        $registry = $factory->create([]);
        self::assertFalse($registry->isAvailable());
    }
}

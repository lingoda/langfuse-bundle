<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Storage;

use League\Flysystem\FilesystemOperator;
use Lingoda\LangfuseBundle\Storage\StorageFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class StorageFactoryTest extends TestCase
{
    private ContainerInterface&MockObject $mockContainer;
    private StorageFactory $factory;

    protected function setUp(): void
    {
        $this->mockContainer = $this->createMock(ContainerInterface::class);
        $this->factory = new StorageFactory($this->mockContainer);
    }

    public function testCreateWithNullConfig(): void
    {
        $registry = $this->factory->create(null);
        self::assertFalse($registry->isAvailable());
    }

    public function testCreateWithEmptyConfig(): void
    {
        $registry = $this->factory->create([]);
        self::assertFalse($registry->isAvailable());
    }

    public function testCreateWithEmptyStorageConfig(): void
    {
        $config = ['storage' => []];
        $registry = $this->factory->create($config);
        self::assertFalse($registry->isAvailable());
    }

    public function testCreateWithFlysystemService(): void
    {
        if (!interface_exists(FilesystemOperator::class)) {
            self::markTestSkipped('Flysystem is not available');
        }

        $mockFilesystem = $this->createMock(FilesystemOperator::class);

        $this->mockContainer
            ->expects(self::once())
            ->method('has')
            ->with('flysystem.storage')
            ->willReturn(true)
        ;

        $this->mockContainer
            ->expects(self::once())
            ->method('get')
            ->with('flysystem.storage')
            ->willReturn($mockFilesystem)
        ;

        $config = [
            'storage' => [
                'service' => 'flysystem.storage'
            ]
        ];

        $registry = $this->factory->create($config);
        self::assertTrue($registry->supports($mockFilesystem));
    }

    public function testCreateWithNonExistentService(): void
    {
        $this->mockContainer
            ->expects(self::once())
            ->method('has')
            ->with('non.existent.service')
            ->willReturn(false)
        ;

        $this->mockContainer
            ->expects(self::never())
            ->method('get')
        ;

        $config = [
            'storage' => [
                'service' => 'non.existent.service'
            ]
        ];

        $registry = $this->factory->create($config);
        self::assertFalse($registry->isAvailable());
    }

    public function testCreateWithInvalidServiceType(): void
    {
        $invalidService = new \stdClass();

        $this->mockContainer
            ->expects(self::once())
            ->method('has')
            ->with('invalid.service')
            ->willReturn(true)
        ;

        $this->mockContainer
            ->expects(self::once())
            ->method('get')
            ->with('invalid.service')
            ->willReturn($invalidService)
        ;

        $config = [
            'storage' => [
                'service' => 'invalid.service'
            ]
        ];

        $registry = $this->factory->create($config);
        self::assertFalse($registry->isAvailable());
    }

    public function testCreateWithThrowingService(): void
    {
        $this->mockContainer
            ->expects(self::once())
            ->method('has')
            ->with('throwing.service')
            ->willReturn(true)
        ;

        $this->mockContainer
            ->expects(self::once())
            ->method('get')
            ->with('throwing.service')
            ->willThrowException(new \RuntimeException('Service error'))
        ;

        $config = [
            'storage' => [
                'service' => 'throwing.service'
            ]
        ];

        $registry = $this->factory->create($config);
        self::assertFalse($registry->isAvailable());
    }

    public function testCreateWithPathStorage(): void
    {
        $config = [
            'storage' => [
                'path' => '/tmp/prompts'
            ]
        ];

        $registry = $this->factory->create($config);
        self::assertTrue($registry->supports('/tmp/prompts'));
    }

    public function testCreateWithEmptyPath(): void
    {
        $config = [
            'storage' => [
                'path' => ''
            ]
        ];

        $registry = $this->factory->create($config);
        self::assertFalse($registry->isAvailable());
    }

    public function testCreateWithNullPath(): void
    {
        $config = [
            'storage' => [
                'path' => null
            ]
        ];

        $registry = $this->factory->create($config);
        self::assertFalse($registry->isAvailable());
    }

    public function testServiceTakesPriorityOverPath(): void
    {
        if (!interface_exists(FilesystemOperator::class)) {
            self::markTestSkipped('Flysystem is not available');
        }

        $mockFilesystem = $this->createMock(FilesystemOperator::class);

        $this->mockContainer
            ->expects(self::once())
            ->method('has')
            ->with('flysystem.storage')
            ->willReturn(true)
        ;

        $this->mockContainer
            ->expects(self::once())
            ->method('get')
            ->with('flysystem.storage')
            ->willReturn($mockFilesystem)
        ;

        $config = [
            'storage' => [
                'service' => 'flysystem.storage',
                'path' => '/tmp/prompts' // This should be ignored
            ]
        ];

        $registry = $this->factory->create($config);

        // Should use Flysystem, not path storage
        self::assertTrue($registry->supports($mockFilesystem));
        self::assertFalse($registry->supports('/tmp/prompts'));
    }

    public function testCreateWithComplexConfig(): void
    {
        $config = [
            'other_key' => 'ignored',
            'storage' => [
                'path' => '/var/cache/prompts',
                'other_storage_key' => 'ignored'
            ],
            'another_key' => 'also_ignored'
        ];

        $registry = $this->factory->create($config);
        self::assertTrue($registry->supports('/var/cache/prompts'));
    }

    public function testCreateWithMissingStorageKey(): void
    {
        $config = [
            'other_settings' => ['key' => 'value'],
            'not_storage' => ['path' => '/tmp']
        ];

        $registry = $this->factory->create($config);
        self::assertFalse($registry->isAvailable());
    }
}

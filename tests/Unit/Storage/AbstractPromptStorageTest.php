<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Storage;

use Lingoda\LangfuseBundle\Naming\PromptIdentifier;
use Lingoda\LangfuseBundle\Storage\AbstractPromptStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AbstractPromptStorageTest extends TestCase
{
    private AbstractPromptStorage $storage;
    private LoggerInterface&MockObject $mockLogger;
    private PromptIdentifier&MockObject $mockIdentifier;

    protected function setUp(): void
    {
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->mockIdentifier = $this->createMock(PromptIdentifier::class);

        $this->storage = new class($this->mockLogger, $this->mockIdentifier) extends AbstractPromptStorage {
            public array $loadResults = [];
            public array $saveResults = [];
            public array $existsResults = [];
            public array $deleteResults = [];
            public array $listResults = [];
            public bool $availabilityResult = true;
            public array $exceptions = [];

            public function supports(mixed $config): bool
            {
                return true;
            }

            protected function doLoad(string $name, ?int $version = null, ?string $label = null): ?array
            {
                if (isset($this->exceptions['load'])) {
                    throw $this->exceptions['load'];
                }
                return $this->loadResults[$name] ?? null;
            }

            protected function doSave(string $name, array $promptData, ?int $version = null, ?string $label = null): bool
            {
                if (isset($this->exceptions['save'])) {
                    throw $this->exceptions['save'];
                }
                return $this->saveResults[$name] ?? true;
            }

            protected function doExists(string $name, ?int $version = null, ?string $label = null): bool
            {
                if (isset($this->exceptions['exists'])) {
                    throw $this->exceptions['exists'];
                }
                return $this->existsResults[$name] ?? false;
            }

            protected function doDelete(string $name, ?int $version = null, ?string $label = null): bool
            {
                if (isset($this->exceptions['delete'])) {
                    throw $this->exceptions['delete'];
                }
                return $this->deleteResults[$name] ?? true;
            }

            protected function doList(): array
            {
                if (isset($this->exceptions['list'])) {
                    throw $this->exceptions['list'];
                }
                return $this->listResults;
            }

            protected function doIsAvailable(): bool
            {
                if (isset($this->exceptions['available'])) {
                    throw $this->exceptions['available'];
                }
                return $this->availabilityResult;
            }

            public function callBuildFilePath(string $name, ?int $version = null, ?string $label = null): string
            {
                return $this->buildFilePath($name, $version, $label);
            }

            public function callEncode(array $data): string
            {
                return $this->encode($data);
            }

            public function callDecode(string $content): ?array
            {
                return $this->decode($content);
            }
        };
    }

    public function testConstructorWithDefaults(): void
    {
        $storage = new class() extends AbstractPromptStorage {
            public function supports(mixed $config): bool
            {
                return true;
            }
            protected function doLoad(string $name, ?int $version = null, ?string $label = null): ?array
            {
                return null;
            }
            protected function doSave(string $name, array $promptData, ?int $version = null, ?string $label = null): bool
            {
                return true;
            }
            protected function doExists(string $name, ?int $version = null, ?string $label = null): bool
            {
                return false;
            }
            protected function doDelete(string $name, ?int $version = null, ?string $label = null): bool
            {
                return true;
            }
            protected function doList(): array
            {
                return [];
            }
            protected function doIsAvailable(): bool
            {
                return true;
            }
        };

        // Should not throw exception - uses default NullLogger and PromptIdentifier
        self::assertTrue($storage->isAvailable());
    }

    public function testLoadSuccess(): void
    {
        $promptData = ['name' => 'test', 'content' => 'Hello {{name}}'];
        $this->storage->loadResults['test-prompt'] = $promptData;

        $result = $this->storage->load('test-prompt', 1, 'production');

        self::assertEquals($promptData, $result);
    }

    public function testLoadWithException(): void
    {
        $this->storage->exceptions['load'] = new \RuntimeException('Storage error');

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with('Failed to load prompt', [
                'name' => 'failing-prompt',
                'version' => 2,
                'label' => 'staging',
                'storage' => $this->storage::class,
                'error' => 'Storage error',
            ])
        ;

        $result = $this->storage->load('failing-prompt', 2, 'staging');

        self::assertNull($result);
    }

    public function testSaveSuccess(): void
    {
        $promptData = ['name' => 'test', 'content' => 'Hello'];
        $this->storage->saveResults['test-prompt'] = true;

        $result = $this->storage->save('test-prompt', $promptData, 1, 'production');

        self::assertTrue($result);
    }

    public function testSaveWithException(): void
    {
        $this->storage->exceptions['save'] = new \RuntimeException('Save failed');

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with('Failed to save prompt', [
                'name' => 'failing-save',
                'version' => null,
                'label' => null,
                'storage' => $this->storage::class,
                'error' => 'Save failed',
            ])
        ;

        $result = $this->storage->save('failing-save', ['data' => 'test']);

        self::assertFalse($result);
    }

    public function testExistsSuccess(): void
    {
        $this->storage->existsResults['existing-prompt'] = true;

        $result = $this->storage->exists('existing-prompt', 5);

        self::assertTrue($result);
    }

    public function testExistsWithException(): void
    {
        $this->storage->exceptions['exists'] = new \RuntimeException('Check failed');

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with('Failed to check prompt existence', [
                'name' => 'check-prompt',
                'version' => 1,
                'label' => 'test',
                'storage' => $this->storage::class,
                'error' => 'Check failed',
            ])
        ;

        $result = $this->storage->exists('check-prompt', 1, 'test');

        self::assertFalse($result);
    }

    public function testDeleteSuccess(): void
    {
        $this->storage->deleteResults['delete-prompt'] = true;

        $result = $this->storage->delete('delete-prompt');

        self::assertTrue($result);
    }

    public function testDeleteWithException(): void
    {
        $this->storage->exceptions['delete'] = new \RuntimeException('Delete failed');

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with('Failed to delete prompt', [
                'name' => 'delete-prompt',
                'version' => null,
                'label' => null,
                'storage' => $this->storage::class,
                'error' => 'Delete failed',
            ])
        ;

        $result = $this->storage->delete('delete-prompt');

        self::assertFalse($result);
    }

    public function testListSuccess(): void
    {
        $expectedList = ['prompt1', 'prompt2', 'prompt3'];
        $this->storage->listResults = $expectedList;

        $result = $this->storage->list();

        self::assertEquals($expectedList, $result);
    }

    public function testListWithException(): void
    {
        $this->storage->exceptions['list'] = new \RuntimeException('List failed');

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with('Failed to list prompts', [
                'storage' => $this->storage::class,
                'error' => 'List failed',
            ])
        ;

        $result = $this->storage->list();

        self::assertEquals([], $result);
    }

    public function testIsAvailableSuccess(): void
    {
        $this->storage->availabilityResult = true;

        $result = $this->storage->isAvailable();

        self::assertTrue($result);
    }

    public function testIsAvailableWithException(): void
    {
        $this->storage->exceptions['available'] = new \RuntimeException('Availability check failed');

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with('Failed to check storage availability', [
                'storage' => $this->storage::class,
                'error' => 'Availability check failed',
            ])
        ;

        $result = $this->storage->isAvailable();

        self::assertFalse($result);
    }

    public function testBuildFilePath(): void
    {
        $this->mockIdentifier
            ->expects(self::once())
            ->method('buildIdentifier')
            ->with('test-prompt', 2, 'production')
            ->willReturn('test-prompt_v2_lprodhash')
        ;

        $result = $this->storage->callBuildFilePath('test-prompt', 2, 'production');

        self::assertEquals('test-prompt_v2_lprodhash.json', $result);
    }

    public function testEncodeArray(): void
    {
        $data = ['name' => 'test', 'content' => 'Hello {{name}}', 'config' => ['temp' => 0.7]];

        $result = $this->storage->callEncode($data);

        $decoded = json_decode($result, true);
        self::assertEquals($data, $decoded);
        self::assertStringContainsString("\n", $result); // Pretty print
    }

    public function testEncodeWithUnicode(): void
    {
        $data = ['content' => 'Здравствуй, мир! 🌍'];

        $result = $this->storage->callEncode($data);

        self::assertStringContainsString('Здравствуй, мир! 🌍', $result); // Unescaped unicode
    }

    public function testDecodeValidJson(): void
    {
        $originalData = ['name' => 'test', 'content' => 'Hello {{name}}'];
        $json = json_encode($originalData);

        $result = $this->storage->callDecode($json);

        self::assertEquals($originalData, $result);
    }

    public function testDecodeInvalidJson(): void
    {
        $invalidJson = 'invalid json';

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with('Failed to decode prompt content', [
                'storage' => $this->storage::class,
                'content_length' => mb_strlen($invalidJson),
                'error' => 'Syntax error',
            ])
        ;

        $result = $this->storage->callDecode($invalidJson);

        self::assertNull($result);
    }

    public function testDecodeNonArrayJson(): void
    {
        $result = $this->storage->callDecode('"string value"');

        self::assertNull($result);
    }

    public function testDecodeEmptyString(): void
    {
        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
        ;

        $result = $this->storage->callDecode('');

        self::assertNull($result);
    }
}

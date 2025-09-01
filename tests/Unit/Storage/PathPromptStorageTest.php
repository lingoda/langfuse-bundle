<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Storage;

use Lingoda\LangfuseBundle\Naming\PromptIdentifier;
use Lingoda\LangfuseBundle\Storage\PathPromptStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PathPromptStorageTest extends TestCase
{
    private string $tempDir;
    private PathPromptStorage $storage;
    private LoggerInterface&MockObject $mockLogger;
    private PromptIdentifier&MockObject $mockIdentifier;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/langfuse_test_' . uniqid();
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->mockIdentifier = $this->createMock(PromptIdentifier::class);

        $this->storage = new PathPromptStorage($this->tempDir, $this->mockLogger, $this->mockIdentifier);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testSupportsStringConfig(): void
    {
        self::assertTrue($this->storage->supports('/path/to/storage'));
        self::assertTrue($this->storage->supports('relative/path'));
        self::assertFalse($this->storage->supports(['array', 'config']));
        self::assertFalse($this->storage->supports(123));
        self::assertFalse($this->storage->supports(null));
    }

    public function testIsAvailableCreatesDirectory(): void
    {
        self::assertDirectoryDoesNotExist($this->tempDir);

        $result = $this->storage->isAvailable();

        self::assertTrue($result);
        self::assertDirectoryExists($this->tempDir);
        self::assertTrue(is_writable($this->tempDir));
    }

    public function testLoadNonExistentFile(): void
    {
        $this->mockIdentifier
            ->expects(self::once())
            ->method('buildIdentifier')
            ->with('non-existent', null, null)
            ->willReturn('non-existent')
        ;

        $result = $this->storage->load('non-existent');

        self::assertNull($result);
    }

    public function testSaveAndLoadPrompt(): void
    {
        $promptData = [
            'name' => 'test-prompt',
            'content' => 'Hello {{name}}!',
            'config' => ['temperature' => 0.7]
        ];

        $this->mockIdentifier
            ->method('buildIdentifier')
            ->with('test-prompt', 1, 'production')
            ->willReturn('test-prompt_v1_lprodhash')
        ;

        // Save
        $saveResult = $this->storage->save('test-prompt', $promptData, 1, 'production');
        self::assertTrue($saveResult);

        // Verify file exists
        $expectedPath = $this->tempDir . DIRECTORY_SEPARATOR . 'test-prompt_v1_lprodhash.json';
        self::assertFileExists($expectedPath);

        // Load
        $loadResult = $this->storage->load('test-prompt', 1, 'production');
        self::assertEquals($promptData, $loadResult);
    }

    public function testExistsForSavedPrompt(): void
    {
        $promptData = ['name' => 'exists-test'];

        $this->mockIdentifier
            ->method('buildIdentifier')
            ->with('exists-test', null, null)
            ->willReturn('exists-test')
        ;

        self::assertFalse($this->storage->exists('exists-test'));

        $this->storage->save('exists-test', $promptData);

        self::assertTrue($this->storage->exists('exists-test'));
    }

    public function testDeleteExistingPrompt(): void
    {
        $promptData = ['name' => 'delete-test'];

        $this->mockIdentifier
            ->method('buildIdentifier')
            ->with('delete-test', null, null)
            ->willReturn('delete-test')
        ;

        // Save first
        $this->storage->save('delete-test', $promptData);
        self::assertTrue($this->storage->exists('delete-test'));

        // Delete
        $result = $this->storage->delete('delete-test');
        self::assertTrue($result);
        self::assertFalse($this->storage->exists('delete-test'));
    }

    public function testDeleteNonExistentPrompt(): void
    {
        $this->mockIdentifier
            ->expects(self::once())
            ->method('buildIdentifier')
            ->with('non-existent', null, null)
            ->willReturn('non-existent')
        ;

        $result = $this->storage->delete('non-existent');

        self::assertFalse($result);
    }

    public function testListEmptyDirectory(): void
    {
        $result = $this->storage->list();

        self::assertEquals([], $result);
    }

    public function testListWithPrompts(): void
    {
        // Create some test files
        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/prompt1.json', '{"name": "prompt1"}');
        file_put_contents($this->tempDir . '/prompt2_v1.json', '{"name": "prompt2"}');
        file_put_contents($this->tempDir . '/not-json.txt', 'ignored');
        file_put_contents($this->tempDir . '/prompt3_v2_lhash.json', '{"name": "prompt3"}');

        $result = $this->storage->list();

        sort($result); // Sort for consistent testing
        self::assertEquals(['prompt1', 'prompt2_v1', 'prompt3_v2_lhash'], $result);
    }

    public function testListNonExistentDirectory(): void
    {
        $nonExistentStorage = new PathPromptStorage('/path/does/not/exist', $this->mockLogger, $this->mockIdentifier);

        $result = $nonExistentStorage->list();

        self::assertEquals([], $result);
    }

    public function testSaveCreatesDirectoryRecursively(): void
    {
        $deepPath = $this->tempDir . '/deep/nested/path';
        $storage = new PathPromptStorage($deepPath, $this->mockLogger, $this->mockIdentifier);

        $this->mockIdentifier
            ->expects(self::once())
            ->method('buildIdentifier')
            ->willReturn('test')
        ;

        self::assertDirectoryDoesNotExist($deepPath);

        $result = $storage->save('test', ['name' => 'test']);

        self::assertTrue($result);
        self::assertDirectoryExists($deepPath);
    }

    public function testLoadHandlesFileReadError(): void
    {
        // Create a directory with same name as expected file to cause read error
        mkdir($this->tempDir, 0755, true);
        $conflictPath = $this->tempDir . '/conflict.json';
        mkdir($conflictPath); // Create directory instead of file

        $this->mockIdentifier
            ->expects(self::once())
            ->method('buildIdentifier')
            ->willReturn('conflict')
        ;

        // Suppress the expected warning since we're testing error handling
        $result = @$this->storage->load('conflict');

        self::assertNull($result);

        // Cleanup
        rmdir($conflictPath);
    }

    public function testLoadHandlesInvalidJson(): void
    {
        mkdir($this->tempDir, 0755, true);
        $filePath = $this->tempDir . '/invalid.json';
        file_put_contents($filePath, 'invalid json content');

        $this->mockIdentifier
            ->expects(self::once())
            ->method('buildIdentifier')
            ->willReturn('invalid')
        ;

        // Mock the warning that will be logged by decode method
        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
        ;

        $result = $this->storage->load('invalid');

        self::assertNull($result);
    }

    public function testBuildFilePathWithStorageDirectory(): void
    {
        $this->mockIdentifier
            ->expects(self::once())
            ->method('buildIdentifier')
            ->with('test', 1, 'label')
            ->willReturn('test_v1_lhash')
        ;

        // Use reflection to test protected method
        $reflection = new \ReflectionClass($this->storage);
        $method = $reflection->getMethod('buildFilePath');

        $result = $method->invoke($this->storage, 'test', 1, 'label');

        $expected = $this->tempDir . DIRECTORY_SEPARATOR . 'test_v1_lhash.json';
        self::assertEquals($expected, $result);
    }

    public function testIsAvailableWithReadOnlyDirectory(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // Skip on Windows as chmod behavior differs
            self::markTestSkipped('chmod behavior differs on Windows');
        }

        // Create directory first, then make it read-only
        mkdir($this->tempDir, 0755, true);
        chmod($this->tempDir, 0555); // Read-only

        $result = $this->storage->isAvailable();

        self::assertFalse($result);

        // Restore permissions for cleanup
        chmod($this->tempDir, 0755);
    }

    public function testIsAvailableHandlesDirectoryCreationFailure(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // Skip on Windows due to permission model differences
            self::markTestSkipped('Permission tests behave differently on Windows');
        }

        // Try to create directory at an impossible location
        $impossiblePath = '/root/test_' . uniqid();
        $storage = new PathPromptStorage($impossiblePath, $this->mockLogger, $this->mockIdentifier);

        // On systems where we don't have permission, this should return false rather than throwing
        // The exact behavior depends on system permissions
        try {
            // Suppress expected warnings from mkdir attempts
            $result = @$storage->isAvailable();
            // If no exception is thrown, the method should return false for failure
            self::assertFalse($result);
        } catch (\RuntimeException $e) {
            // If exception is thrown, verify the message
            self::assertStringContainsString('Cannot create storage directory', $e->getMessage());
        }
    }
}

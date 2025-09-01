<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Storage;

use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Lingoda\LangfuseBundle\Naming\PromptIdentifier;
use Lingoda\LangfuseBundle\Storage\FlysystemPromptStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FlysystemPromptStorageTest extends TestCase
{
    private FilesystemOperator&MockObject $mockFilesystem;
    private LoggerInterface&MockObject $mockLogger;
    private PromptIdentifier&MockObject $mockIdentifier;
    private FlysystemPromptStorage $storage;

    protected function setUp(): void
    {
        $this->mockFilesystem = $this->createMock(FilesystemOperator::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->mockIdentifier = $this->createMock(PromptIdentifier::class);

        $this->storage = new FlysystemPromptStorage(
            $this->mockFilesystem,
            $this->mockLogger,
            $this->mockIdentifier
        );
    }

    public function testSupportsFilesystemOperator(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);

        self::assertTrue($this->storage->supports($filesystem));
        self::assertFalse($this->storage->supports('/path/string'));
        self::assertFalse($this->storage->supports(['array', 'config']));
        self::assertFalse($this->storage->supports(null));
    }

    public function testIsAvailableAlwaysReturnsTrue(): void
    {
        self::assertTrue($this->storage->isAvailable());
    }

    public function testLoadExistingFile(): void
    {
        $promptData = [
            'name' => 'test-prompt',
            'content' => 'Hello {{name}}!',
            'config' => ['temperature' => 0.7]
        ];
        $jsonContent = json_encode($promptData, JSON_PRETTY_PRINT);

        $this->mockIdentifier
            ->expects(self::once())
            ->method('buildIdentifier')
            ->with('test-prompt', 1, 'production')
            ->willReturn('test-prompt_v1_lprodhash')
        ;

        $this->mockFilesystem
            ->expects(self::once())
            ->method('fileExists')
            ->with('test-prompt_v1_lprodhash.json')
            ->willReturn(true)
        ;

        $this->mockFilesystem
            ->expects(self::once())
            ->method('read')
            ->with('test-prompt_v1_lprodhash.json')
            ->willReturn($jsonContent)
        ;

        $result = $this->storage->load('test-prompt', 1, 'production');

        self::assertEquals($promptData, $result);
    }

    public function testLoadNonExistentFile(): void
    {
        $this->mockIdentifier
            ->expects(self::once())
            ->method('buildIdentifier')
            ->with('non-existent', null, null)
            ->willReturn('non-existent')
        ;

        $this->mockFilesystem
            ->expects(self::once())
            ->method('fileExists')
            ->with('non-existent.json')
            ->willReturn(false)
        ;

        $this->mockFilesystem
            ->expects(self::never())
            ->method('read')
        ;

        $result = $this->storage->load('non-existent');

        self::assertNull($result);
    }

    public function testLoadWithFilesystemException(): void
    {
        $this->mockIdentifier
            ->method('buildIdentifier')
            ->willReturn('error-prompt')
        ;

        $this->mockFilesystem
            ->expects(self::once())
            ->method('fileExists')
            ->willThrowException(new class('Filesystem error') extends \Exception implements FilesystemException {})
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with('Failed to load prompt', self::arrayHasKey('error'))
        ;

        $result = $this->storage->load('error-prompt');

        self::assertNull($result);
    }

    public function testSavePrompt(): void
    {
        $promptData = [
            'name' => 'save-prompt',
            'content' => 'Content to save',
            'version' => 2
        ];

        $this->mockIdentifier
            ->expects(self::once())
            ->method('buildIdentifier')
            ->with('save-prompt', 2, null)
            ->willReturn('save-prompt_v2')
        ;

        $this->mockFilesystem
            ->expects(self::once())
            ->method('write')
            ->with('save-prompt_v2.json', self::isType('string'))
        ;

        $result = $this->storage->save('save-prompt', $promptData, 2);

        self::assertTrue($result);
    }

    public function testSaveWithFilesystemException(): void
    {
        $promptData = ['name' => 'fail-save'];

        $this->mockIdentifier
            ->method('buildIdentifier')
            ->willReturn('fail-save')
        ;

        $this->mockFilesystem
            ->expects(self::once())
            ->method('write')
            ->willThrowException(new class('Write failed') extends \Exception implements FilesystemException {})
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with('Failed to save prompt', self::arrayHasKey('error'))
        ;

        $result = $this->storage->save('fail-save', $promptData);

        self::assertFalse($result);
    }

    public function testExistsReturnsTrueForExistingFile(): void
    {
        $this->mockIdentifier
            ->expects(self::once())
            ->method('buildIdentifier')
            ->with('existing-prompt', null, null)
            ->willReturn('existing-prompt')
        ;

        $this->mockFilesystem
            ->expects(self::once())
            ->method('fileExists')
            ->with('existing-prompt.json')
            ->willReturn(true)
        ;

        $result = $this->storage->exists('existing-prompt');

        self::assertTrue($result);
    }

    public function testExistsReturnsFalseForNonExistentFile(): void
    {
        $this->mockIdentifier
            ->expects(self::once())
            ->method('buildIdentifier')
            ->willReturn('non-existent')
        ;

        $this->mockFilesystem
            ->expects(self::once())
            ->method('fileExists')
            ->willReturn(false)
        ;

        $result = $this->storage->exists('non-existent');

        self::assertFalse($result);
    }

    public function testDeleteExistingFile(): void
    {
        $this->mockIdentifier
            ->expects(self::once())
            ->method('buildIdentifier')
            ->with('delete-prompt', null, null)
            ->willReturn('delete-prompt')
        ;

        $this->mockFilesystem
            ->expects(self::once())
            ->method('fileExists')
            ->with('delete-prompt.json')
            ->willReturn(true)
        ;

        $this->mockFilesystem
            ->expects(self::once())
            ->method('delete')
            ->with('delete-prompt.json')
        ;

        $result = $this->storage->delete('delete-prompt');

        self::assertTrue($result);
    }

    public function testDeleteNonExistentFile(): void
    {
        $this->mockIdentifier
            ->expects(self::once())
            ->method('buildIdentifier')
            ->willReturn('non-existent')
        ;

        $this->mockFilesystem
            ->expects(self::once())
            ->method('fileExists')
            ->with('non-existent.json')
            ->willReturn(false)
        ;

        $this->mockFilesystem
            ->expects(self::never())
            ->method('delete')
        ;

        $result = $this->storage->delete('non-existent');

        self::assertFalse($result);
    }

    public function testListFiles(): void
    {
        // Create mock file attributes
        $file1 = $this->createMock(FileAttributes::class);
        $file1->method('isFile')->willReturn(true);
        $file1->method('path')->willReturn('prompt1.json');

        $file2 = $this->createMock(FileAttributes::class);
        $file2->method('isFile')->willReturn(true);
        $file2->method('path')->willReturn('prompt2_v1.json');

        $file3 = $this->createMock(FileAttributes::class);
        $file3->method('isFile')->willReturn(true);
        $file3->method('path')->willReturn('not-json.txt');

        $file4 = $this->createMock(FileAttributes::class);
        $file4->method('isFile')->willReturn(false); // Directory
        $file4->method('path')->willReturn('subdirectory.json');

        $directoryListing = new DirectoryListing([$file1, $file2, $file3, $file4]);

        $this->mockFilesystem
            ->expects(self::once())
            ->method('listContents')
            ->with('/', false)
            ->willReturn($directoryListing)
        ;

        $result = $this->storage->list();

        // Should only include JSON files (not directories or non-JSON files)
        self::assertEquals(['prompt1', 'prompt2_v1'], $result);
    }

    public function testListFilesWithEmptyDirectory(): void
    {
        $directoryListing = new DirectoryListing([]);

        $this->mockFilesystem
            ->expects(self::once())
            ->method('listContents')
            ->willReturn($directoryListing)
        ;

        $result = $this->storage->list();

        self::assertEquals([], $result);
    }

    public function testListWithFilesystemException(): void
    {
        $this->mockFilesystem
            ->expects(self::once())
            ->method('listContents')
            ->willThrowException(new class('List failed') extends \Exception implements FilesystemException {})
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with('Failed to list prompts', self::arrayHasKey('error'))
        ;

        $result = $this->storage->list();

        self::assertEquals([], $result);
    }

    public function testLoadWithInvalidJson(): void
    {
        $this->mockIdentifier
            ->method('buildIdentifier')
            ->willReturn('invalid-json')
        ;

        $this->mockFilesystem
            ->method('fileExists')
            ->willReturn(true)
        ;

        $this->mockFilesystem
            ->expects(self::once())
            ->method('read')
            ->willReturn('invalid json content')
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with('Failed to decode prompt content', self::arrayHasKey('error'))
        ;

        $result = $this->storage->load('invalid-json');

        self::assertNull($result);
    }

    public function testSaveEncodesDataCorrectly(): void
    {
        $promptData = [
            'name' => 'encoding-test',
            'content' => 'Test with special chars: äöü 🌍',
            'metadata' => ['author' => 'test-user']
        ];

        $this->mockIdentifier
            ->method('buildIdentifier')
            ->willReturn('encoding-test')
        ;

        $capturedContent = null;
        $this->mockFilesystem
            ->expects(self::once())
            ->method('write')
            ->with('encoding-test.json', self::callback(static function ($content) use (&$capturedContent) {
                $capturedContent = $content;
                return true;
            }))
        ;

        $this->storage->save('encoding-test', $promptData);

        // Verify the content is properly encoded JSON
        $decodedContent = json_decode($capturedContent, true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals($promptData, $decodedContent);

        // Verify Unicode characters are preserved
        self::assertStringContainsString('äöü 🌍', $capturedContent);
    }

    public function testConstructorWithDefaults(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $storage = new FlysystemPromptStorage($filesystem);

        self::assertTrue($storage->isAvailable());
    }
}

<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Storage;

use Lingoda\LangfuseBundle\Naming\PromptIdentifier;
use Psr\Log\LoggerInterface;

/**
 * Simple path-based prompt storage implementation.
 * Handles prompt storage using native PHP file operations in a specified directory.
 */
final readonly class PathPromptStorage extends AbstractPromptStorage
{
    public function __construct(
        private string $storagePath,
        ?LoggerInterface $logger = null,
        ?PromptIdentifier $identifier = null
    ) {
        parent::__construct($logger, $identifier);
    }

    protected function doLoad(string $name, ?int $version = null, ?string $label = null): ?array
    {
        $filePath = $this->buildFilePath($name, $version, $label);
        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        return $this->decode($content);
    }

    /**
     * @throws \JsonException
     */
    protected function doSave(string $name, array $promptData, ?int $version = null, ?string $label = null): bool
    {
        $this->ensureStorageDirectoryExists();

        $filePath = $this->buildFilePath($name, $version, $label);
        $content = $this->encode($promptData);

        return file_put_contents($filePath, $content) !== false;
    }

    protected function doExists(string $name, ?int $version = null, ?string $label = null): bool
    {
        $filePath = $this->buildFilePath($name, $version, $label);

        return file_exists($filePath);
    }

    protected function doDelete(string $name, ?int $version = null, ?string $label = null): bool
    {
        $filePath = $this->buildFilePath($name, $version, $label);
        if (file_exists($filePath)) {
            return unlink($filePath);
        }

        return false;
    }

    protected function doList(): array
    {
        if (!is_dir($this->storagePath)) {
            return [];
        }

        $files = [];
        $ext = '.json';
        $iterator = new \DirectoryIterator($this->storagePath);

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $ext)) {
                $files[] = basename($file->getFilename(), $ext);
            }
        }

        return $files;
    }

    protected function doIsAvailable(): bool
    {
        $this->ensureStorageDirectoryExists();

        return is_dir($this->storagePath) && is_writable($this->storagePath);
    }

    public function supports(mixed $config): bool
    {
        return is_string($config);
    }

    /**
     * Ensure the storage directory exists and is writable.
     */
    private function ensureStorageDirectoryExists(): void
    {
        // Check if directory already exists first
        if (!is_dir($this->storagePath)) {
            if (!mkdir($this->storagePath, 0755, true) && !is_dir($this->storagePath)) {
                throw new \RuntimeException(sprintf('Cannot create storage directory: %s', $this->storagePath));
            }
        }

        if (!is_writable($this->storagePath)) {
            throw new \RuntimeException(sprintf('Storage directory is not writable: %s', $this->storagePath));
        }
    }

    /**
     * Build full file path for storage including the storage directory.
     */
    protected function buildFilePath(string $name, ?int $version = null, ?string $label = null): string
    {
        $filename = parent::buildFilePath($name, $version, $label);

        return $this->storagePath . DIRECTORY_SEPARATOR . $filename;
    }
}

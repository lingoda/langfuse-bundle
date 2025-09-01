<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Storage;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Lingoda\LangfuseBundle\Naming\PromptIdentifier;
use Psr\Log\LoggerInterface;

/**
 * Flysystem-based prompt storage implementation.
 * Handles prompt storage using any Flysystem adapter.
 */
final readonly class FlysystemPromptStorage extends AbstractPromptStorage
{
    public function __construct(
        private FilesystemOperator $storage,
        ?LoggerInterface $logger = null,
        ?PromptIdentifier $identifier = null
    ) {
        parent::__construct($logger, $identifier);
    }

    /**
     * @throws FilesystemException
     */
    protected function doLoad(string $name, ?int $version = null, ?string $label = null): ?array
    {
        $filePath = $this->buildFilePath($name, $version, $label);
        if (!$this->storage->fileExists($filePath)) {
            return null;
        }

        $content = $this->storage->read($filePath);

        return $this->decode($content);
    }

    /**
     * @throws FilesystemException
     * @throws \JsonException
     */
    protected function doSave(string $name, array $promptData, ?int $version = null, ?string $label = null): bool
    {
        $filePath = $this->buildFilePath($name, $version, $label);
        $content = $this->encode($promptData);

        $this->storage->write($filePath, $content);

        return true;
    }

    /**
     * @throws FilesystemException
     */
    protected function doExists(string $name, ?int $version = null, ?string $label = null): bool
    {
        $filePath = $this->buildFilePath($name, $version, $label);

        return $this->storage->fileExists($filePath);
    }

    /**
     * @throws FilesystemException
     */
    protected function doDelete(string $name, ?int $version = null, ?string $label = null): bool
    {
        $filePath = $this->buildFilePath($name, $version, $label);
        if ($this->storage->fileExists($filePath)) {
            $this->storage->delete($filePath);

            return true;
        }

        return false;
    }

    /**
     * @throws FilesystemException
     */
    protected function doList(): array
    {
        $files = [];
        $listing = $this->storage->listContents('/', false);

        $ext = '.json';
        foreach ($listing as $item) {
            if ($item->isFile() && str_ends_with($item->path(), $ext)) {
                $files[] = basename($item->path(), $ext);
            }
        }

        return $files;
    }

    protected function doIsAvailable(): bool
    {
        return true;
    }

    public function supports(mixed $config): bool
    {
        return $config instanceof FilesystemOperator;
    }
}

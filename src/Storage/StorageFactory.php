<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Storage;

use League\Flysystem\FilesystemOperator;
use Lingoda\LangfuseBundle\PhpStan\Types;

/**
 * Factory for creating appropriate storage services based on configuration.
 *
 * @phpstan-import-type FallbackConfig from Types
 */
final readonly class StorageFactory
{
    public function __construct(
        private ?FilesystemOperator $filesystemService = null
    ) {
    }

    /**
     * Create a PromptStorageSelector with appropriate storage implementations.
     *
     * @param FallbackConfig|null $fallbackConfig Configuration array or null if disabled
     */
    public function create(?array $fallbackConfig): PromptStorageRegistry
    {
        $storages = [];
        $config = null;

        // If fallback is disabled or empty
        if (empty($fallbackConfig)) {
            return new PromptStorageRegistry($storages, $config);
        }

        // Get storage configuration
        $storageConfig = $fallbackConfig['storage'] ?? [];

        // Priority 1: Injected filesystem service (when configured)
        if ($this->filesystemService !== null) {
            $storages[] = new FlysystemPromptStorage($this->filesystemService);
            $config = $this->filesystemService;
        }
        // Priority 2: Path-based storage
        elseif (!empty($storageConfig['path']) && is_string($storageConfig['path'])) {
            $path = $storageConfig['path'];
            $storages[] = new PathPromptStorage($path);
            $config = $path;
        }

        return new PromptStorageRegistry($storages, $config);
    }
}

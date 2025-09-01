<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Storage;

use League\Flysystem\FilesystemOperator;
use Lingoda\LangfuseBundle\PhpStan\Types;
use Psr\Container\ContainerInterface;

/**
 * Factory for creating appropriate storage services based on configuration.
 *
 * @phpstan-import-type FallbackConfig from Types
 */
final readonly class StorageFactory
{
    public function __construct(
        private ContainerInterface $container
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

        // Priority 1: Service-based storage (Flysystem)
        if (!empty($storageConfig['service']) && is_string($storageConfig['service'])) {
            $serviceId = $storageConfig['service'];
            if ($this->container->has($serviceId)) {
                try {
                    $service = $this->container->get($serviceId);
                    if ($service instanceof FilesystemOperator) {
                        $storages[] = new FlysystemPromptStorage($service);
                        $config = $service;
                    }
                } catch (\Throwable) {
                    // Service doesn't exist or is not a FilesystemOperator, skip
                }
            }
        }

        // Priority 2: Path-based storage (only if no service configured)
        elseif (!empty($storageConfig['path']) && is_string($storageConfig['path'])) {
            $path = $storageConfig['path'];
            $storages[] = new PathPromptStorage($path);
            $config = $path;
        }

        return new PromptStorageRegistry($storages, $config);
    }
}

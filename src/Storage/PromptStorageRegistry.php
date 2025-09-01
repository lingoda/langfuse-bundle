<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Storage;

/**
 * Selects and delegates to the appropriate storage strategy based on configuration.
 * Acts as a strategy context that chooses the right storage implementation.
 */
final class PromptStorageRegistry implements PromptStorageInterface
{
    /**
     * @param array<PromptStorageInterface> $storages Available storage implementations
     * @param mixed $config Storage configuration (can be null, string path, or FilesystemOperator)
     */
    public function __construct(
        private readonly array $storages,
        private readonly mixed $config = null
    ) {
    }

    public function load(string $name, ?int $version = null, ?string $label = null): ?array
    {
        return $this->getStorage()?->load($name, $version, $label);
    }

    /**
     * @param array<string, mixed> $promptData
     */
    public function save(string $name, array $promptData, ?int $version = null, ?string $label = null): bool
    {
        return $this->getStorage()?->save($name, $promptData, $version, $label) ?? false;
    }

    public function exists(string $name, ?int $version = null, ?string $label = null): bool
    {
        return $this->getStorage()?->exists($name, $version, $label) ?? false;
    }

    public function delete(string $name, ?int $version = null, ?string $label = null): bool
    {
        return $this->getStorage()?->delete($name, $version, $label) ?? false;
    }

    public function list(): array
    {
        return $this->getStorage()?->list() ?? [];
    }

    public function isAvailable(): bool
    {
        return $this->getStorage()?->isAvailable() ?? false;
    }

    public function supports(mixed $config): bool
    {
        return $this->findStorageFor($config) !== null;
    }

    /**
     * Get the appropriate storage implementation for the current configuration.
     */
    private function getStorage(): ?PromptStorageInterface
    {
        return $this->findStorageFor($this->config);
    }

    /**
     * Find a storage implementation that supports the given configuration.
     */
    private function findStorageFor(mixed $config): ?PromptStorageInterface
    {
        if ($config === null) {
            return null;
        }

        foreach ($this->storages as $storage) {
            if ($storage->supports($config)) {
                return $storage;
            }
        }

        return null;
    }
}

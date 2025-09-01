<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Storage;

interface PromptStorageInterface
{
    /**
     * Load prompt data from storage.
     *
     * @return array<string, mixed>|null Prompt data or null if not found
     */
    public function load(string $name, ?int $version = null, ?string $label = null): ?array;

    /**
     * Save prompt data to storage.
     *
     * @param array<string, mixed> $promptData Prompt data to save
     */
    public function save(string $name, array $promptData, ?int $version = null, ?string $label = null): bool;

    /**
     * Check if prompt exists in storage.
     */
    public function exists(string $name, ?int $version = null, ?string $label = null): bool;

    /**
     * Delete prompt from storage.
     */
    public function delete(string $name, ?int $version = null, ?string $label = null): bool;

    /**
     * List all prompts in storage.
     *
     * @return array<string> List of prompt names
     */
    public function list(): array;

    /**
     * Check if storage is available.
     */
    public function isAvailable(): bool;

    /**
     * Check if this storage implementation supports the given configuration.
     */
    public function supports(mixed $config): bool;
}

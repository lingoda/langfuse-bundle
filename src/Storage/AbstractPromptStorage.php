<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Storage;

use Lingoda\LangfuseBundle\Naming\PromptIdentifier;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Abstract base class for prompt storage implementations.
 * Provides common functionality for file naming, encoding, and decoding.
 */
abstract readonly class AbstractPromptStorage implements PromptStorageInterface
{
    protected LoggerInterface $logger;
    protected PromptIdentifier $identifier;

    public function __construct(?LoggerInterface $logger = null, ?PromptIdentifier $identifier = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->identifier = $identifier ?? new PromptIdentifier();
    }

    public function load(string $name, ?int $version = null, ?string $label = null): ?array
    {
        try {
            return $this->doLoad($name, $version, $label);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load prompt', [
                'name' => $name,
                'version' => $version,
                'label' => $label,
                'storage' => static::class,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function save(string $name, array $promptData, ?int $version = null, ?string $label = null): bool
    {
        try {
            return $this->doSave($name, $promptData, $version, $label);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to save prompt', [
                'name' => $name,
                'version' => $version,
                'label' => $label,
                'storage' => static::class,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function exists(string $name, ?int $version = null, ?string $label = null): bool
    {
        try {
            return $this->doExists($name, $version, $label);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to check prompt existence', [
                'name' => $name,
                'version' => $version,
                'label' => $label,
                'storage' => static::class,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function delete(string $name, ?int $version = null, ?string $label = null): bool
    {
        try {
            return $this->doDelete($name, $version, $label);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to delete prompt', [
                'name' => $name,
                'version' => $version,
                'label' => $label,
                'storage' => static::class,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function list(): array
    {
        try {
            return $this->doList();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to list prompts', [
                'storage' => static::class,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function isAvailable(): bool
    {
        try {
            return $this->doIsAvailable();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to check storage availability', [
                'storage' => static::class,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Load prompt data from storage.
     *
     * @return array<string, mixed>|null Prompt data or null if not found
     */
    abstract protected function doLoad(string $name, ?int $version = null, ?string $label = null): ?array;
    /**
     * Save prompt data to storage.
     *
     * @param array<string, mixed> $promptData Prompt data to save
     */
    abstract protected function doSave(string $name, array $promptData, ?int $version = null, ?string $label = null): bool;
    abstract protected function doExists(string $name, ?int $version = null, ?string $label = null): bool;
    abstract protected function doDelete(string $name, ?int $version = null, ?string $label = null): bool;
    /**
     * List all prompts in storage.
     *
     * @return array<string> List of prompt names
     */
    abstract protected function doList(): array;
    abstract protected function doIsAvailable(): bool;

    /**
     * Build file path for storage.
     */
    protected function buildFilePath(string $name, ?int $version = null, ?string $label = null): string
    {
        return $this->identifier->buildIdentifier($name, $version, $label) . '.json';
    }

    /**
     * Encode content for storage.
     *
     * @param array<string, mixed> $data
     *
     * @throws \JsonException
     */
    protected function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Decode content from storage.
     *
     * @return array<mixed>|null
     */
    protected function decode(string $content): ?array
    {
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to decode prompt content', [
                'storage' => static::class,
                'content_length' => mb_strlen($content),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}

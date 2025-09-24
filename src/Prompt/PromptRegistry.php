<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Prompt;

use Lingoda\AiSdk\Prompt\Conversation;
use Lingoda\LangfuseBundle\Cache\PromptCache;
use Lingoda\LangfuseBundle\Client\PromptClient;
use Lingoda\LangfuseBundle\Deserialization\PromptDeserializer;
use Lingoda\LangfuseBundle\Exception\DeserializationException;
use Lingoda\LangfuseBundle\Exception\LangfuseException;
use Lingoda\LangfuseBundle\Storage\PromptStorageRegistry;

/**
 * Registry for prompt retrieval that returns deserialized Conversation objects.
 * Acts as the main entry point for developers to get prompts.
 * Handles caching and storage fallback orchestration.
 */
final readonly class PromptRegistry implements PromptRegistryInterface
{
    public function __construct(
        private PromptClient $client,
        private PromptCache $cache,
        private PromptStorageRegistry $storage,
        private PromptDeserializer $deserializer
    ) {
    }

    public function get(string $name, ?int $version = null, ?string $label = null, bool $useCache = true): Conversation
    {
        try {
            $promptData = $this->getRawPrompt($name, $version, $label, $useCache);

            return $this->deserializer->deserialize($promptData);
        } catch (DeserializationException $e) {
            throw new LangfuseException(
                sprintf('Failed to deserialize prompt "%s": %s', $name, $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * @param array<string, mixed>       $parameters
     *
     * @throws LangfuseException
     * @return Conversation
     */
    public function getCompiled(string $name, array $parameters, ?int $version = null, ?string $label = null, bool $useCache = true): Conversation
    {
        $conversation = $this->get($name, $version, $label, $useCache);

        return new Conversation(
            $conversation->getUserPrompt()->withParameters($parameters),
            $conversation->getSystemPrompt()?->withParameters($parameters),
            $conversation->getAssistantPrompt()?->withParameters($parameters),
            $conversation->isSanitized()
        );
    }

    public function has(string $name, ?int $version = null, ?string $label = null): bool
    {
        return $this->storage->exists($name, $version, $label);
    }

    /**
     * @throws LangfuseException
     */
    public function getRawPrompt(string $name, ?int $version = null, ?string $label = null, bool $useCache = true): array
    {
        if (!$useCache || !$this->cache->isAvailable()) {
            return $this->fetchOrFallback($name, $version, $label);
        }

        $cacheKey = $this->cache->buildKey($name, $version, $label);

        return $this->cache->get($cacheKey, fn (): array => $this->fetchOrFallback($name, $version, $label));
    }

    /**
     * Fetch raw prompt data with storage fallback.
     *
     * @throws LangfuseException
     *
     * @return array<string, mixed> Prompt data
     */
    private function fetchOrFallback(string $name, ?int $version, ?string $label): array
    {
        try {
            // Try API first
            $prompt = $this->client->getPromptFromAPI($name, $version, $label);

            // Store in fallback storage if available
            if ($this->storage->isAvailable()) {
                $this->storage->save($name, $prompt, $version, $label);
            }

            return $prompt;
        } catch (LangfuseException $e) {
            // Try fallback storage if API fails
            if ($this->storage->isAvailable()) {
                $stored = $this->storage->load($name, $version, $label);
                if ($stored !== null) {
                    return $stored;
                }
            }
            throw $e;
        }
    }
}

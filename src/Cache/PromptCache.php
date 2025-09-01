<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Cache;

use Lingoda\LangfuseBundle\Naming\PromptIdentifier;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Handles prompt caching using Symfony Cache.
 */
final class PromptCache
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ?CacheInterface $cache = null,
        private readonly int $defaultTtl = 3600,
        private readonly PromptIdentifier $identifier = new PromptIdentifier(),
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Get cached prompt or execute callback to fetch and cache it.
     *
     * @param string $key Cache key
     * @param callable(): array<string, mixed> $callback Function to fetch data on cache miss
     *
     * @return array<string, mixed>
     */
    public function get(string $key, callable $callback): array
    {
        if (!$this->cache) {
            $this->logger->debug('Cache not available, fetching directly', ['key' => $key]);
            return $callback();
        }

        try {
            $result = $this->cache->get($key, function (ItemInterface $item) use ($callback, $key): array {
                $this->logger->debug('Cache miss, fetching from callback', ['key' => $key, 'ttl' => $this->defaultTtl]);
                $item->expiresAfter($this->defaultTtl);

                return $callback();
            });

            $this->logger->debug('Cache operation completed', ['key' => $key]);
            return $result;
        } catch (\Exception $e) {
            $this->logger->warning('Cache get failed, falling back to callback', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return $callback();
        }
    }

    /**
     * Store prompt in cache.
     *
     * @param array<string, mixed> $data Data to cache
     */
    public function set(string $key, array $data, ?int $ttl = null): bool
    {
        if (!$this->cache) {
            $this->logger->debug('Cache not available, cannot store', ['key' => $key]);
            return false;
        }

        try {
            $result = $this->cache->get($key, function (ItemInterface $item) use ($data, $ttl): array {
                $item->expiresAfter($ttl ?? $this->defaultTtl);

                return $data;
            }) === $data;

            $this->logger->debug('Cache set operation', [
                'key' => $key,
                'ttl' => $ttl ?? $this->defaultTtl,
                'success' => $result,
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to set cache', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Delete specific prompt from cache.
     *
     * @throws InvalidArgumentException
     */
    public function delete(string $key): bool
    {
        if (!$this->cache) {
            $this->logger->debug('Cache not available, cannot delete', ['key' => $key]);
            return false;
        }

        try {
            $result = $this->cache->delete($key);
            $this->logger->debug('Cache delete operation', ['key' => $key, 'success' => $result]);
            return $result;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to delete from cache', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Build cache key for prompt.
     */
    public function buildKey(string $name, ?int $version = null, ?string $label = null): string
    {
        return 'langfuse_prompt_' . $this->identifier->buildIdentifier($name, $version, $label);
    }

    /**
     * Check if caching is available.
     */
    public function isAvailable(): bool
    {
        return $this->cache !== null;
    }
}

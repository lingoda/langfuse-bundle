<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Cache;

use Lingoda\LangfuseBundle\Cache\PromptCache;
use Lingoda\LangfuseBundle\Naming\PromptIdentifier;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class PromptCacheTest extends TestCase
{
    private CacheInterface&MockObject $mockCache;
    private LoggerInterface&MockObject $mockLogger;
    private PromptIdentifier&MockObject $mockIdentifier;

    protected function setUp(): void
    {
        $this->mockCache = $this->createMock(CacheInterface::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->mockIdentifier = $this->createMock(PromptIdentifier::class);
    }

    public function testConstructorWithDefaults(): void
    {
        $cache = new PromptCache();

        self::assertFalse($cache->isAvailable());
    }

    public function testConstructorWithCache(): void
    {
        $cache = new PromptCache($this->mockCache);

        self::assertTrue($cache->isAvailable());
    }

    public function testGetWithoutCache(): void
    {
        $cache = new PromptCache(null, 3600, $this->mockIdentifier, $this->mockLogger);

        $callbackData = ['name' => 'test', 'content' => 'Hello'];
        $callback = fn () => $callbackData;

        $this->mockLogger
            ->expects(self::once())
            ->method('debug')
            ->with('Cache not available, fetching directly', ['key' => 'test-key'])
        ;

        $result = $cache->get('test-key', $callback);

        self::assertEquals($callbackData, $result);
    }

    public function testGetWithCacheHit(): void
    {
        $cache = new PromptCache($this->mockCache, 3600, $this->mockIdentifier, $this->mockLogger);

        $cachedData = ['name' => 'cached', 'content' => 'Cached content'];

        $this->mockCache
            ->expects(self::once())
            ->method('get')
            ->with('cache-key', self::isInstanceOf(\Closure::class))
            ->willReturn($cachedData)
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('debug')
            ->with('Cache operation completed', ['key' => 'cache-key'])
        ;

        $callback = fn () => ['should' => 'not be called'];
        $result = $cache->get('cache-key', $callback);

        self::assertEquals($cachedData, $result);
    }

    public function testGetWithCacheMiss(): void
    {
        $cache = new PromptCache($this->mockCache, 1800, $this->mockIdentifier, $this->mockLogger);

        $callbackData = ['name' => 'fresh', 'content' => 'Fresh content'];
        $callback = fn () => $callbackData;

        $mockItem = $this->createMock(ItemInterface::class);
        $mockItem
            ->expects(self::once())
            ->method('expiresAfter')
            ->with(1800)
        ;

        $this->mockCache
            ->expects(self::once())
            ->method('get')
            ->with('miss-key', self::isInstanceOf(\Closure::class))
            ->willReturnCallback(fn ($key, $closure) => $closure($mockItem))
        ;

        $this->mockLogger
            ->expects(self::exactly(2))
            ->method('debug')
        ;

        $result = $cache->get('miss-key', $callback);

        self::assertEquals($callbackData, $result);
    }

    public function testGetWithCacheException(): void
    {
        $cache = new PromptCache($this->mockCache, 3600, $this->mockIdentifier, $this->mockLogger);

        $callbackData = ['name' => 'fallback', 'content' => 'Fallback content'];
        $callback = fn () => $callbackData;

        $this->mockCache
            ->expects(self::once())
            ->method('get')
            ->willThrowException(new \RuntimeException('Cache error'))
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with('Cache get failed, falling back to callback', [
                'key' => 'error-key',
                'error' => 'Cache error',
            ])
        ;

        $result = $cache->get('error-key', $callback);

        self::assertEquals($callbackData, $result);
    }

    public function testSetWithoutCache(): void
    {
        $cache = new PromptCache(null, 3600, $this->mockIdentifier, $this->mockLogger);

        $this->mockLogger
            ->expects(self::once())
            ->method('debug')
            ->with('Cache not available, cannot store', ['key' => 'test-key'])
        ;

        $result = $cache->set('test-key', ['data' => 'value']);

        self::assertFalse($result);
    }

    public function testSetWithCache(): void
    {
        $cache = new PromptCache($this->mockCache, 7200, $this->mockIdentifier, $this->mockLogger);

        $data = ['name' => 'test', 'content' => 'Test content'];

        $mockItem = $this->createMock(ItemInterface::class);
        $mockItem
            ->expects(self::once())
            ->method('expiresAfter')
            ->with(7200)
        ;

        $this->mockCache
            ->expects(self::once())
            ->method('get')
            ->with('set-key', self::isInstanceOf(\Closure::class))
            ->willReturnCallback(fn ($key, $closure) => $closure($mockItem))
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('debug')
            ->with('Cache set operation', [
                'key' => 'set-key',
                'ttl' => 7200,
                'success' => true,
            ])
        ;

        $result = $cache->set('set-key', $data);

        self::assertTrue($result);
    }

    public function testSetWithCustomTtl(): void
    {
        $cache = new PromptCache($this->mockCache, 3600, $this->mockIdentifier, $this->mockLogger);

        $data = ['name' => 'test'];

        $mockItem = $this->createMock(ItemInterface::class);
        $mockItem
            ->expects(self::once())
            ->method('expiresAfter')
            ->with(1200)
        ;

        $this->mockCache
            ->expects(self::once())
            ->method('get')
            ->willReturnCallback(fn ($key, $closure) => $closure($mockItem))
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('debug')
            ->with('Cache set operation', [
                'key' => 'custom-ttl-key',
                'ttl' => 1200,
                'success' => true,
            ])
        ;

        $result = $cache->set('custom-ttl-key', $data, 1200);

        self::assertTrue($result);
    }

    public function testSetWithException(): void
    {
        $cache = new PromptCache($this->mockCache, 3600, $this->mockIdentifier, $this->mockLogger);

        $this->mockCache
            ->expects(self::once())
            ->method('get')
            ->willThrowException(new \RuntimeException('Set failed'))
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with('Failed to set cache', [
                'key' => 'error-key',
                'error' => 'Set failed',
            ])
        ;

        $result = $cache->set('error-key', ['data' => 'value']);

        self::assertFalse($result);
    }

    public function testDeleteWithoutCache(): void
    {
        $cache = new PromptCache(null, 3600, $this->mockIdentifier, $this->mockLogger);

        $this->mockLogger
            ->expects(self::once())
            ->method('debug')
            ->with('Cache not available, cannot delete', ['key' => 'delete-key'])
        ;

        $result = $cache->delete('delete-key');

        self::assertFalse($result);
    }

    public function testDeleteWithCache(): void
    {
        $cache = new PromptCache($this->mockCache, 3600, $this->mockIdentifier, $this->mockLogger);

        $this->mockCache
            ->expects(self::once())
            ->method('delete')
            ->with('delete-key')
            ->willReturn(true)
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('debug')
            ->with('Cache delete operation', ['key' => 'delete-key', 'success' => true])
        ;

        $result = $cache->delete('delete-key');

        self::assertTrue($result);
    }

    public function testDeleteWithException(): void
    {
        $cache = new PromptCache($this->mockCache, 3600, $this->mockIdentifier, $this->mockLogger);

        $this->mockCache
            ->expects(self::once())
            ->method('delete')
            ->willThrowException(new \RuntimeException('Delete failed'))
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with('Failed to delete from cache', [
                'key' => 'error-key',
                'error' => 'Delete failed',
            ])
        ;

        $result = $cache->delete('error-key');

        self::assertFalse($result);
    }

    public function testBuildKey(): void
    {
        $cache = new PromptCache($this->mockCache, 3600, $this->mockIdentifier, $this->mockLogger);

        $this->mockIdentifier
            ->expects(self::once())
            ->method('buildIdentifier')
            ->with('test-prompt', 2, 'staging')
            ->willReturn('test-prompt_v2_lstaging_hash')
        ;

        $result = $cache->buildKey('test-prompt', 2, 'staging');

        self::assertEquals('langfuse_prompt_test-prompt_v2_lstaging_hash', $result);
    }

    public function testBuildKeyWithMinimalParams(): void
    {
        $cache = new PromptCache($this->mockCache, 3600, $this->mockIdentifier, $this->mockLogger);

        $this->mockIdentifier
            ->expects(self::once())
            ->method('buildIdentifier')
            ->with('simple-prompt', null, null)
            ->willReturn('simple-prompt')
        ;

        $result = $cache->buildKey('simple-prompt');

        self::assertEquals('langfuse_prompt_simple-prompt', $result);
    }

    public function testIsAvailableWithCache(): void
    {
        $cache = new PromptCache($this->mockCache);

        self::assertTrue($cache->isAvailable());
    }

    public function testIsAvailableWithoutCache(): void
    {
        $cache = new PromptCache(null);

        self::assertFalse($cache->isAvailable());
    }

    public function testDefaultTtlUsed(): void
    {
        $customTtl = 9999;
        $cache = new PromptCache($this->mockCache, $customTtl, $this->mockIdentifier, $this->mockLogger);

        $mockItem = $this->createMock(ItemInterface::class);
        $mockItem
            ->expects(self::once())
            ->method('expiresAfter')
            ->with($customTtl)
        ;

        $this->mockCache
            ->expects(self::once())
            ->method('get')
            ->willReturnCallback(fn ($key, $closure) => $closure($mockItem))
        ;

        $cache->get('test-key', fn () => ['data' => 'value']);
    }
}

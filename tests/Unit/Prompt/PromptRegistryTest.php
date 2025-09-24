<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Prompt;

use Lingoda\AiSdk\Prompt\AssistantPrompt;
use Lingoda\AiSdk\Prompt\Conversation;
use Lingoda\AiSdk\Prompt\SystemPrompt;
use Lingoda\AiSdk\Prompt\UserPrompt;
use Lingoda\LangfuseBundle\Cache\PromptCache;
use Lingoda\LangfuseBundle\Client\PromptClient;
use Lingoda\LangfuseBundle\Deserialization\PromptDeserializer;
use Lingoda\LangfuseBundle\Exception\DeserializationException;
use Lingoda\LangfuseBundle\Exception\LangfuseException;
use Lingoda\LangfuseBundle\Prompt\PromptRegistry;
use Lingoda\LangfuseBundle\Storage\PromptStorageRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PromptRegistryTest extends TestCase
{
    private PromptClient&MockObject $mockClient;
    private PromptCache&MockObject $mockCache;
    private PromptStorageRegistry&MockObject $mockStorage;
    private PromptDeserializer&MockObject $mockDeserializer;
    private PromptRegistry $registry;

    protected function setUp(): void
    {
        $this->mockClient = $this->createMock(PromptClient::class);
        $this->mockCache = $this->createMock(PromptCache::class);
        $this->mockStorage = $this->createMock(PromptStorageRegistry::class);
        $this->mockDeserializer = $this->createMock(PromptDeserializer::class);

        $this->registry = new PromptRegistry(
            $this->mockClient,
            $this->mockCache,
            $this->mockStorage,
            $this->mockDeserializer
        );
    }

    public function testGetPromptFromAPIWithCache(): void
    {
        $promptData = [
            'name' => 'test-prompt',
            'prompt' => [['role' => 'user', 'content' => 'Hello']]
        ];
        $conversation = $this->createMock(Conversation::class);

        $this->mockCache
            ->expects(self::once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->mockCache
            ->expects(self::once())
            ->method('buildKey')
            ->with('test-prompt', 1, 'prod')
            ->willReturn('cache_key_test')
        ;

        $this->mockCache
            ->expects(self::once())
            ->method('get')
            ->with('cache_key_test', self::isInstanceOf(\Closure::class))
            ->willReturn($promptData)
        ;

        $this->mockDeserializer
            ->expects(self::once())
            ->method('deserialize')
            ->with($promptData)
            ->willReturn($conversation)
        ;

        $result = $this->registry->get('test-prompt', 1, 'prod');

        self::assertSame($conversation, $result);
    }

    public function testGetPromptWithoutCache(): void
    {
        $promptData = [
            'name' => 'no-cache-prompt',
            'prompt' => [['role' => 'user', 'content' => 'Hello']]
        ];
        $conversation = $this->createMock(Conversation::class);

        $this->mockClient
            ->expects(self::once())
            ->method('getPromptFromAPI')
            ->with('no-cache-prompt', 2, 'staging')
            ->willReturn($promptData)
        ;

        $this->mockStorage
            ->expects(self::once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->mockStorage
            ->expects(self::once())
            ->method('save')
            ->with('no-cache-prompt', $promptData, 2, 'staging')
            ->willReturn(true)
        ;

        $this->mockDeserializer
            ->expects(self::once())
            ->method('deserialize')
            ->with($promptData)
            ->willReturn($conversation)
        ;

        $result = $this->registry->get('no-cache-prompt', 2, 'staging', false);

        self::assertSame($conversation, $result);
    }

    public function testGetPromptFallsBackToStorage(): void
    {
        $promptData = [
            'name' => 'fallback-prompt',
            'prompt' => [['role' => 'user', 'content' => 'Fallback']]
        ];
        $conversation = $this->createMock(Conversation::class);

        $this->mockClient
            ->expects(self::once())
            ->method('getPromptFromAPI')
            ->with('fallback-prompt', null, null)
            ->willThrowException(new LangfuseException('API error'))
        ;

        $this->mockStorage
            ->expects(self::once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->mockStorage
            ->expects(self::once())
            ->method('load')
            ->with('fallback-prompt', null, null)
            ->willReturn($promptData)
        ;

        $this->mockDeserializer
            ->expects(self::once())
            ->method('deserialize')
            ->with($promptData)
            ->willReturn($conversation)
        ;

        $result = $this->registry->get('fallback-prompt', null, null, false);

        self::assertSame($conversation, $result);
    }

    public function testGetPromptThrowsWhenApiFails(): void
    {
        $this->mockClient
            ->expects(self::once())
            ->method('getPromptFromAPI')
            ->with('missing-prompt', null, null)
            ->willThrowException(new LangfuseException('Prompt not found'))
        ;

        $this->mockStorage
            ->expects(self::once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->mockStorage
            ->expects(self::once())
            ->method('load')
            ->with('missing-prompt', null, null)
            ->willReturn(null)
        ;

        $this->expectException(LangfuseException::class);
        $this->expectExceptionMessage('Prompt not found');

        $this->registry->get('missing-prompt', null, null, false);
    }

    public function testGetPromptWithDeserializationError(): void
    {
        $promptData = [
            'name' => 'invalid-prompt',
            'prompt' => 'invalid-structure'
        ];

        $this->mockClient
            ->expects(self::once())
            ->method('getPromptFromAPI')
            ->with('invalid-prompt', null, null)
            ->willReturn($promptData)
        ;

        $this->mockStorage
            ->expects(self::once())
            ->method('isAvailable')
            ->willReturn(false)
        ;

        $this->mockDeserializer
            ->expects(self::once())
            ->method('deserialize')
            ->with($promptData)
            ->willThrowException(new DeserializationException('Invalid format'))
        ;

        $this->expectException(LangfuseException::class);
        $this->expectExceptionMessage('Failed to deserialize prompt "invalid-prompt": Invalid format');

        $this->registry->get('invalid-prompt', null, null, false);
    }

    public function testGetPromptWithCacheMissCallsApi(): void
    {
        $promptData = [
            'name' => 'cache-miss-prompt',
            'prompt' => [['role' => 'user', 'content' => 'Cache miss']]
        ];
        $conversation = $this->createMock(Conversation::class);

        $this->mockCache
            ->expects(self::once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->mockCache
            ->expects(self::once())
            ->method('buildKey')
            ->with('cache-miss-prompt', null, null)
            ->willReturn('cache_key_miss')
        ;

        $this->mockCache
            ->expects(self::once())
            ->method('get')
            ->with('cache_key_miss', self::isInstanceOf(\Closure::class))
            ->willReturnCallback(function ($key, $callback) {
                return $callback(); // Simulate cache miss by calling the callback
            })
        ;

        $this->mockClient
            ->expects(self::once())
            ->method('getPromptFromAPI')
            ->with('cache-miss-prompt', null, null)
            ->willReturn($promptData)
        ;

        $this->mockStorage
            ->expects(self::once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->mockStorage
            ->expects(self::once())
            ->method('save')
            ->with('cache-miss-prompt', $promptData, null, null)
        ;

        $this->mockDeserializer
            ->expects(self::once())
            ->method('deserialize')
            ->with($promptData)
            ->willReturn($conversation)
        ;

        $result = $this->registry->get('cache-miss-prompt');

        self::assertSame($conversation, $result);
    }

    public function testHasPrompt(): void
    {
        $this->mockStorage
            ->expects(self::once())
            ->method('exists')
            ->with('existing-prompt', 3, 'label')
            ->willReturn(true)
        ;

        $result = $this->registry->has('existing-prompt', 3, 'label');

        self::assertTrue($result);
    }

    public function testHasPromptReturnsFalse(): void
    {
        $this->mockStorage
            ->expects(self::once())
            ->method('exists')
            ->with('non-existing-prompt', null, null)
            ->willReturn(false)
        ;

        $result = $this->registry->has('non-existing-prompt');

        self::assertFalse($result);
    }

    public function testGetRawPromptWithCache(): void
    {
        $promptData = ['name' => 'raw-prompt', 'content' => 'Raw content'];

        $this->mockCache
            ->expects(self::once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->mockCache
            ->expects(self::once())
            ->method('buildKey')
            ->with('raw-prompt', null, null)
            ->willReturn('raw_cache_key')
        ;

        $this->mockCache
            ->expects(self::once())
            ->method('get')
            ->with('raw_cache_key', self::isInstanceOf(\Closure::class))
            ->willReturn($promptData)
        ;

        $result = $this->registry->getRawPrompt('raw-prompt');

        self::assertEquals($promptData, $result);
    }

    public function testGetRawPromptWithoutCache(): void
    {
        $promptData = ['name' => 'raw-no-cache', 'content' => 'No cache content'];

        $this->mockCache
            ->expects(self::once())
            ->method('isAvailable')
            ->willReturn(false)
        ;

        $this->mockClient
            ->expects(self::once())
            ->method('getPromptFromAPI')
            ->with('raw-no-cache', null, null)
            ->willReturn($promptData)
        ;

        $this->mockStorage
            ->expects(self::once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->mockStorage
            ->expects(self::once())
            ->method('save')
            ->with('raw-no-cache', $promptData, null, null)
        ;

        $result = $this->registry->getRawPrompt('raw-no-cache', null, null, true);

        self::assertEquals($promptData, $result);
    }

    public function testGetRawPromptSkipsCache(): void
    {
        $promptData = ['name' => 'skip-cache', 'content' => 'Skip cache'];

        $this->mockCache
            ->expects(self::never())
            ->method('isAvailable')
        ;

        $this->mockClient
            ->expects(self::once())
            ->method('getPromptFromAPI')
            ->with('skip-cache', null, null)
            ->willReturn($promptData)
        ;

        $this->mockStorage
            ->expects(self::once())
            ->method('isAvailable')
            ->willReturn(false)
        ;

        $result = $this->registry->getRawPrompt('skip-cache', null, null, false);

        self::assertEquals($promptData, $result);
    }

    public function testGetRawPromptWithStorageUnavailable(): void
    {
        $promptData = ['name' => 'no-storage', 'content' => 'No storage'];

        $this->mockClient
            ->expects(self::once())
            ->method('getPromptFromAPI')
            ->with('no-storage', null, null)
            ->willReturn($promptData)
        ;

        $this->mockStorage
            ->expects(self::once())
            ->method('isAvailable')
            ->willReturn(false)
        ;

        $this->mockStorage
            ->expects(self::never())
            ->method('save')
        ;

        $result = $this->registry->getRawPrompt('no-storage', null, null, false);

        self::assertEquals($promptData, $result);
    }

    public function testGetCompiledPrompt(): void
    {
        $promptData = [
            'name' => 'no-cache-prompt',
            'prompt' => [
                ['role' => 'user', 'content' => 'Hello {{world}}'],
                ['role' => 'system', 'content' => 'System message {{info}}'],
                ['role' => 'assistant', 'content' => 'Assistant message {{data}}']
            ]
        ];

        $this->mockClient
            ->expects(self::once())
            ->method('getPromptFromAPI')
            ->with('no-cache-prompt', 2, 'staging')
            ->willReturn($promptData)
        ;

        $this->mockStorage
            ->expects(self::once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->mockStorage
            ->expects(self::once())
            ->method('save')
            ->with('no-cache-prompt', $promptData, 2, 'staging')
            ->willReturn(true)
        ;

        $this->mockDeserializer
            ->expects(self::once())
            ->method('deserialize')
            ->with($promptData)
            ->willReturnCallback(function ($data) {
                $userPrompt = new UserPrompt($data['prompt'][0]['content']);
                $systemPrompt = new SystemPrompt($data['prompt'][1]['content']);
                $assistantPrompt = new AssistantPrompt($data['prompt'][2]['content']);

                return new Conversation($userPrompt, $systemPrompt, $assistantPrompt);
            })
        ;

        $result = $this->registry->getCompiled(
            'no-cache-prompt',
            [
                'world' => 'Earth',
                'info' => 'Some info',
                'data' => 'Some data'
            ],
            2,
            'staging',
            false
        );

        self::assertSame('Hello Earth', $result->getUserPrompt()->getContent());
        self::assertSame('System message Some info', $result->getSystemPrompt()?->getContent());
        self::assertSame('Assistant message Some data', $result->getAssistantPrompt()?->getContent());
    }
}

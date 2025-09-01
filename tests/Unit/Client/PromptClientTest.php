<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Client;

use Dropsolid\LangFuse\Client;
use Dropsolid\LangFuse\DTO\ClientConfig;
use Lingoda\LangfuseBundle\Client\PromptClient;
use Lingoda\LangfuseBundle\Client\TraceClient;
use Lingoda\LangfuseBundle\Exception\LangfuseException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class PromptClientTest extends TestCase
{
    private TraceClient&MockObject $mockTraceClient;
    private HttpClientInterface&MockObject $mockHttpClient;
    private Client&MockObject $mockClient;
    private ClientConfig&MockObject $mockConfig;
    private PromptClient $promptClient;

    protected function setUp(): void
    {
        $this->mockTraceClient = $this->createMock(TraceClient::class);
        $this->mockHttpClient = $this->createMock(HttpClientInterface::class);
        $this->mockClient = $this->createMock(Client::class);
        $this->mockConfig = $this->createMock(ClientConfig::class);

        $this->mockConfig->host = 'https://api.langfuse.com';
        $this->mockConfig->method('getAuthHeader')->willReturn('Bearer test-token');

        $this->mockTraceClient->method('getClient')->willReturn($this->mockClient);
        $this->mockClient->method('getConfig')->willReturn($this->mockConfig);

        $this->promptClient = new PromptClient($this->mockTraceClient, $this->mockHttpClient);
    }

    public function testGetPromptFromAPIWithNameOnly(): void
    {
        $promptData = [
            'name' => 'test-prompt',
            'version' => 1,
            'prompt' => 'Hello {{name}}',
            'config' => ['temperature' => 0.7],
        ];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getContent')->willReturn(json_encode($promptData));

        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'GET',
                'https://api.langfuse.com/api/public/prompts',
                [
                    'query' => ['name' => 'test-prompt'],
                    'headers' => [
                        'Authorization' => 'Bearer test-token',
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'timeout' => 30,
                ]
            )
            ->willReturn($mockResponse)
        ;

        $result = $this->promptClient->getPromptFromAPI('test-prompt');

        self::assertEquals($promptData, $result);
    }

    public function testGetPromptFromAPIWithVersion(): void
    {
        $promptData = [
            'name' => 'versioned-prompt',
            'version' => 2,
            'prompt' => 'Version 2 content',
        ];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getContent')->willReturn(json_encode($promptData));

        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'GET',
                'https://api.langfuse.com/api/public/prompts',
                self::callback(fn ($options) => $options['query']['name'] === 'versioned-prompt' &&
                           $options['query']['version'] === '2')
            )
            ->willReturn($mockResponse)
        ;

        $result = $this->promptClient->getPromptFromAPI('versioned-prompt', 2);

        self::assertEquals($promptData, $result);
    }

    public function testGetPromptFromAPIWithLabel(): void
    {
        $promptData = [
            'name' => 'labeled-prompt',
            'label' => 'production',
            'prompt' => 'Production prompt',
        ];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getContent')->willReturn(json_encode($promptData));

        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'GET',
                'https://api.langfuse.com/api/public/prompts',
                self::callback(fn ($options) => $options['query']['name'] === 'labeled-prompt' &&
                           $options['query']['label'] === 'production' &&
                           !isset($options['query']['version']))
            )
            ->willReturn($mockResponse)
        ;

        $result = $this->promptClient->getPromptFromAPI('labeled-prompt', null, 'production');

        self::assertEquals($promptData, $result);
    }

    public function testGetPromptFromAPIWithAllParameters(): void
    {
        $promptData = ['name' => 'full-prompt'];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getContent')->willReturn(json_encode($promptData));

        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'GET',
                'https://api.langfuse.com/api/public/prompts',
                self::callback(fn ($options) => $options['query']['name'] === 'full-prompt' &&
                           $options['query']['version'] === '3' &&
                           $options['query']['label'] === 'staging')
            )
            ->willReturn($mockResponse)
        ;

        $result = $this->promptClient->getPromptFromAPI('full-prompt', 3, 'staging');

        self::assertEquals($promptData, $result);
    }

    public function testGetPromptFromAPIHandles404(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getContent')->willThrowException(
            new \Exception('HTTP 404 returned')
        );

        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse)
        ;

        $this->expectException(LangfuseException::class);
        $this->expectExceptionMessage('Prompt "missing-prompt" not found in Langfuse');
        $this->expectExceptionCode(404);

        $this->promptClient->getPromptFromAPI('missing-prompt');
    }

    public function testGetPromptFromAPIHandlesTransportException(): void
    {
        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->willThrowException(new TransportException('Network error'))
        ;

        $this->expectException(LangfuseException::class);
        $this->expectExceptionMessage('HTTP request failed: Network error');

        $this->promptClient->getPromptFromAPI('test-prompt');
    }

    public function testGetPromptFromAPIHandlesInvalidJson(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getContent')->willReturn('invalid json');

        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse)
        ;

        $this->expectException(LangfuseException::class);
        $this->expectExceptionMessage('Request failed:');

        $this->promptClient->getPromptFromAPI('test-prompt');
    }

    public function testGetPromptFromAPIHandlesNonArrayResponse(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getContent')->willReturn('"string response"');

        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse)
        ;

        $this->expectException(LangfuseException::class);
        $this->expectExceptionMessage('Invalid JSON response from Langfuse API');

        $this->promptClient->getPromptFromAPI('test-prompt');
    }

    public function testGetPromptFromAPIHandlesGenericException(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getContent')->willThrowException(
            new \RuntimeException('Server error')
        );

        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse)
        ;

        $this->expectException(LangfuseException::class);
        $this->expectExceptionMessage('Request failed: Server error');

        $this->promptClient->getPromptFromAPI('test-prompt');
    }

    public function testGetPromptFromAPIWithoutHttpClient(): void
    {
        $promptClient = new PromptClient($this->mockTraceClient);

        $this->expectException(LangfuseException::class);
        $this->expectExceptionMessage('HTTP client not configured for prompt management');

        $promptClient->getPromptFromAPI('test-prompt');
    }

    public function testGetPromptFromAPIHandlesHostWithTrailingSlash(): void
    {
        // Create new mock config with trailing slash
        $mockConfig = $this->createMock(ClientConfig::class);
        $mockConfig->host = 'https://api.langfuse.com/';
        $mockConfig->method('getAuthHeader')->willReturn('Bearer test-token');
        $this->mockClient->method('getConfig')->willReturn($mockConfig);

        $promptData = ['name' => 'test'];
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getContent')->willReturn(json_encode($promptData));

        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'GET',
                'https://api.langfuse.com/api/public/prompts',
                self::anything()
            )
            ->willReturn($mockResponse)
        ;

        $result = $this->promptClient->getPromptFromAPI('test');

        self::assertEquals($promptData, $result);
    }

    public function testGetPromptFromAPIHandlesHostWithoutTrailingSlash(): void
    {
        // Config already set without trailing slash in setUp()
        $promptData = ['name' => 'test'];
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getContent')->willReturn(json_encode($promptData));

        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'GET',
                'https://api.langfuse.com/api/public/prompts',
                self::anything()
            )
            ->willReturn($mockResponse)
        ;

        $result = $this->promptClient->getPromptFromAPI('test');

        self::assertEquals($promptData, $result);
    }

    public function testGetPromptFromAPIWithEmptyResponse(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getContent')->willReturn('{}');

        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse)
        ;

        $result = $this->promptClient->getPromptFromAPI('empty-prompt');

        self::assertEquals([], $result);
    }

    public function testGetPromptFromAPIWithComplexData(): void
    {
        $complexData = [
            'name' => 'complex-prompt',
            'version' => 5,
            'prompt' => 'Complex {{variable}}',
            'config' => [
                'model' => 'gpt-4',
                'temperature' => 0.8,
                'max_tokens' => 1000,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are helpful'],
                    ['role' => 'user', 'content' => '{{input}}'],
                ],
            ],
            'metadata' => [
                'author' => 'test',
                'created_at' => '2024-01-01T00:00:00Z',
                'tags' => ['test', 'production'],
            ],
        ];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getContent')->willReturn(json_encode($complexData));

        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse)
        ;

        $result = $this->promptClient->getPromptFromAPI('complex-prompt');

        self::assertEquals($complexData, $result);
    }

    public function testGetPromptFromAPIHandles404InExceptionMessage(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getContent')->willThrowException(
            new \Exception('Resource not found')
        );

        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse)
        ;

        $this->expectException(LangfuseException::class);
        $this->expectExceptionMessage('Prompt "test" not found in Langfuse');
        $this->expectExceptionCode(404);

        $this->promptClient->getPromptFromAPI('test');
    }
}

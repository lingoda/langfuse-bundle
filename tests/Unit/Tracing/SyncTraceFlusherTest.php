<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Tracing;

use Dropsolid\LangFuse\Observability\Generation;
use Dropsolid\LangFuse\Observability\Trace;
use Lingoda\AiSdk\Result\Usage;
use Lingoda\LangfuseBundle\Client\TraceClient;
use Lingoda\LangfuseBundle\Tracing\SyncTraceFlusher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SyncTraceFlusherTest extends TestCase
{
    private TraceClient&MockObject $mockTraceClient;
    private LoggerInterface&MockObject $mockLogger;
    private SyncTraceFlusher $flusher;

    protected function setUp(): void
    {
        $this->mockTraceClient = $this->createMock(TraceClient::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->flusher = new SyncTraceFlusher($this->mockTraceClient, $this->mockLogger);
    }

    public function testFlushBasicTraceWithoutGeneration(): void
    {
        $mockTrace = $this->createMock(Trace::class);

        $traceData = [
            'name' => 'test-operation',
            'input' => ['content' => 'test input'],
            'output' => ['content' => 'test output'],
            'status' => 'success',
            'metadata' => ['operation_type' => 'basic'],
        ];

        $this->mockTraceClient
            ->expects(self::once())
            ->method('trace')
            ->with($traceData)
            ->willReturn($mockTrace)
        ;

        $mockTrace
            ->expects(self::never())
            ->method('createGeneration')
        ;

        $mockTrace
            ->expects(self::once())
            ->method('end')
            ->with([
                'output' => ['content' => 'test output'],
                'status' => 'success',
            ])
        ;

        $this->mockTraceClient
            ->expects(self::once())
            ->method('flush')
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('debug')
            ->with(
                'Trace flushed synchronously to Langfuse',
                self::callback(
                    static fn ($context) =>
                    $context['trace_name'] === 'test-operation' &&
                    $context['status'] === 'success' &&
                    $context['has_generation'] === false &&
                    $context['has_usage'] === false
                )
            )
        ;

        $this->flusher->flush($traceData);
    }

    public function testFlushAiGenerationWithUsage(): void
    {
        $mockTrace = $this->createMock(Trace::class);
        $mockGeneration = $this->createMock(Generation::class);

        $traceData = [
            'name' => 'ai-completion',
            'input' => ['type' => 'string', 'content' => 'test prompt'],
            'output' => ['type' => 'text', 'content' => 'AI response'],
            'status' => 'success',
            'metadata' => [
                'model' => 'gpt-4',
                'provider' => 'openai',
                'temperature' => 0.7,
            ],
        ];

        $usage = new Usage(
            promptTokens: 10,
            completionTokens: 20,
            totalTokens: 30
        );

        $this->mockTraceClient
            ->expects(self::once())
            ->method('trace')
            ->with($traceData)
            ->willReturn($mockTrace)
        ;

        $mockTrace
            ->expects(self::once())
            ->method('createGeneration')
            ->with(
                name: 'ai-completion',
                model: 'gpt-4',
                modelParameters: ['temperature' => 0.7],
                metadata: $traceData['metadata'],
                input: $traceData['input']
            )
            ->willReturn($mockGeneration)
        ;

        $mockGeneration
            ->expects(self::once())
            ->method('withUsageDetails')
            ->with([
                'prompt_tokens' => 10,
                'completion_tokens' => 20,
                'total_tokens' => 30,
            ])
        ;

        $mockGeneration
            ->expects(self::once())
            ->method('end')
            ->with(['output' => $traceData['output']])
        ;

        $mockTrace
            ->expects(self::once())
            ->method('end')
            ->with(['status' => 'success'])
        ;

        $this->mockTraceClient
            ->expects(self::once())
            ->method('flush')
        ;

        $this->mockLogger
            ->expects(self::exactly(2))
            ->method('debug')
            ->willReturnCallback(function ($message, $context) {
                static $callCount = 0;
                $callCount++;

                if ($callCount === 1) {
                    self::assertEquals('Generation ended successfully', $message);
                    self::assertEquals('gpt-4', $context['model']);
                    self::assertEquals([
                        'prompt_tokens' => 10,
                        'completion_tokens' => 20,
                        'total_tokens' => 30,
                    ], $context['usage']);
                } else {
                    self::assertEquals('Trace flushed synchronously to Langfuse', $message);
                    self::assertTrue($context['has_generation']);
                    self::assertTrue($context['has_usage']);
                }
            })
        ;

        $this->flusher->flush($traceData, $usage);
    }

    public function testFlushWithError(): void
    {
        $mockTrace = $this->createMock(Trace::class);
        $mockGeneration = $this->createMock(Generation::class);

        $traceData = [
            'name' => 'ai-completion',
            'input' => ['content' => 'test prompt'],
            'error' => 'API rate limit exceeded',
            'status' => 'error',
            'metadata' => ['model' => 'gpt-4'],
        ];

        $this->mockTraceClient
            ->expects(self::once())
            ->method('trace')
            ->with($traceData)
            ->willReturn($mockTrace)
        ;

        $mockTrace
            ->expects(self::once())
            ->method('createGeneration')
            ->willReturn($mockGeneration)
        ;

        $mockGeneration
            ->expects(self::once())
            ->method('end')
            ->with([
                'error' => 'API rate limit exceeded',
                'level' => 'ERROR',
            ])
        ;

        $mockTrace
            ->expects(self::once())
            ->method('end')
            ->with([
                'error' => 'API rate limit exceeded',
                'level' => 'ERROR',
                'status' => 'error',
            ])
        ;

        $this->mockTraceClient
            ->expects(self::once())
            ->method('flush')
        ;

        $this->flusher->flush($traceData);
    }

    public function testFlushWithPartialUsage(): void
    {
        $mockTrace = $this->createMock(Trace::class);
        $mockGeneration = $this->createMock(Generation::class);

        $traceData = [
            'name' => 'ai-completion',
            'metadata' => ['model' => 'gpt-4'],
        ];

        // Usage with some zero values
        $usage = new Usage(
            promptTokens: 15,
            completionTokens: 0,
            totalTokens: 15
        );

        $this->mockTraceClient
            ->expects(self::once())
            ->method('trace')
            ->willReturn($mockTrace)
        ;

        $mockTrace
            ->expects(self::once())
            ->method('createGeneration')
            ->willReturn($mockGeneration)
        ;

        $mockGeneration
            ->expects(self::once())
            ->method('withUsageDetails')
            ->with([
                'prompt_tokens' => 15,
                'total_tokens' => 15,
            ])
        ;

        $this->flusher->flush($traceData, $usage);
    }

    public function testFlushHandlesTraceCreationFailure(): void
    {
        $traceData = [
            'name' => 'test-operation',
        ];

        $this->mockTraceClient
            ->expects(self::once())
            ->method('trace')
            ->willReturn(null)
        ;

        $this->mockTraceClient
            ->expects(self::never())
            ->method('flush')
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with(
                'Failed to create trace',
                ['trace_name' => 'test-operation']
            )
        ;

        $this->flusher->flush($traceData);
    }

    public function testFlushHandlesExceptionGracefully(): void
    {
        $traceData = [
            'name' => 'test-operation',
        ];

        $this->mockTraceClient
            ->expects(self::once())
            ->method('trace')
            ->willThrowException(new \RuntimeException('Connection failed'))
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('error')
            ->with(
                'Failed to flush trace to Langfuse',
                self::callback(
                    static fn ($context) =>
                    $context['error'] === 'Connection failed' &&
                    $context['trace_name'] === 'test-operation'
                )
            )
        ;

        // Should not throw exception
        $this->flusher->flush($traceData);
    }

    public function testFlushWithoutLogger(): void
    {
        $flusher = new SyncTraceFlusher($this->mockTraceClient);
        $mockTrace = $this->createMock(Trace::class);

        $traceData = [
            'name' => 'test',
            'status' => 'success',
        ];

        $this->mockTraceClient
            ->expects(self::once())
            ->method('trace')
            ->willReturn($mockTrace)
        ;

        $mockTrace
            ->expects(self::once())
            ->method('end')
        ;

        $this->mockTraceClient
            ->expects(self::once())
            ->method('flush')
        ;

        // Should work without logger
        $flusher->flush($traceData);
    }

    public function testFlushWithModelButNoTemperature(): void
    {
        $mockTrace = $this->createMock(Trace::class);
        $mockGeneration = $this->createMock(Generation::class);

        $traceData = [
            'name' => 'ai-completion',
            'metadata' => [
                'model' => 'gpt-3.5-turbo',
                'provider' => 'openai',
            ],
        ];

        $this->mockTraceClient
            ->expects(self::once())
            ->method('trace')
            ->willReturn($mockTrace)
        ;

        $mockTrace
            ->expects(self::once())
            ->method('createGeneration')
            ->with(
                name: 'ai-completion',
                model: 'gpt-3.5-turbo',
                modelParameters: null,
                metadata: $traceData['metadata'],
                input: null
            )
            ->willReturn($mockGeneration)
        ;

        $mockGeneration->expects(self::never())->method('withUsageDetails');
        $mockGeneration->expects(self::never())->method('end');

        $mockTrace
            ->expects(self::once())
            ->method('end')
            ->with([])
        ;

        $this->flusher->flush($traceData);
    }

    public function testFlushWithEmptyUsageDetails(): void
    {
        $mockTrace = $this->createMock(Trace::class);
        $mockGeneration = $this->createMock(Generation::class);

        $traceData = [
            'name' => 'ai-completion',
            'metadata' => ['model' => 'gpt-4'],
        ];

        // All zero usage
        $usage = new Usage(
            promptTokens: 0,
            completionTokens: 0,
            totalTokens: 0
        );

        $this->mockTraceClient
            ->expects(self::once())
            ->method('trace')
            ->willReturn($mockTrace)
        ;

        $mockTrace
            ->expects(self::once())
            ->method('createGeneration')
            ->willReturn($mockGeneration)
        ;

        // Should not call withUsageDetails when all values are zero
        $mockGeneration
            ->expects(self::never())
            ->method('withUsageDetails')
        ;

        $this->flusher->flush($traceData, $usage);
    }
}

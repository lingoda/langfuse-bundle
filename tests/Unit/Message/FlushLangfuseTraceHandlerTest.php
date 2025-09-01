<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Message;

use Lingoda\AiSdk\Result\Usage;
use Lingoda\LangfuseBundle\Message\FlushLangfuseTrace;
use Lingoda\LangfuseBundle\Message\FlushLangfuseTraceHandler;
use Lingoda\LangfuseBundle\Tracing\SyncTraceFlusher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FlushLangfuseTraceHandlerTest extends TestCase
{
    private SyncTraceFlusher&MockObject $mockSyncFlusher;
    private LoggerInterface&MockObject $mockLogger;
    private FlushLangfuseTraceHandler $handler;

    protected function setUp(): void
    {
        $this->mockSyncFlusher = $this->createMock(SyncTraceFlusher::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);

        $this->handler = new FlushLangfuseTraceHandler(
            $this->mockSyncFlusher,
            $this->mockLogger
        );
    }

    public function testHandleMessageSuccessfully(): void
    {
        $traceData = [
            'name' => 'test-trace',
            'input' => 'Hello world',
            'output' => 'Hello there!'
        ];

        $usage = $this->createMock(Usage::class);
        $message = new FlushLangfuseTrace($traceData, $usage);

        $this->mockLogger
            ->expects(self::exactly(2))
            ->method('info')
        ;

        $this->mockSyncFlusher
            ->expects(self::once())
            ->method('flush')
            ->with($traceData, $usage)
        ;

        $this->handler->__invoke($message);
    }

    public function testHandleMessageWithoutUsage(): void
    {
        $traceData = [
            'name' => 'no-usage-trace',
            'input' => 'Some input'
        ];

        $message = new FlushLangfuseTrace($traceData);

        $this->mockLogger
            ->expects(self::exactly(2))
            ->method('info')
        ;

        $this->mockSyncFlusher
            ->expects(self::once())
            ->method('flush')
            ->with($traceData, null)
        ;

        $this->handler->__invoke($message);
    }

    public function testHandleMessageWithGeneration(): void
    {
        $traceData = [
            'name' => 'generation-trace',
            'metadata' => [
                'model' => 'gpt-4',
                'provider' => 'openai'
            ]
        ];

        $message = new FlushLangfuseTrace($traceData);

        $this->mockLogger
            ->expects(self::exactly(2))
            ->method('info')
        ;

        $this->mockSyncFlusher
            ->expects(self::once())
            ->method('flush')
            ->with($traceData, null)
        ;

        $this->handler->__invoke($message);
    }

    public function testHandleMessageWithoutTraceName(): void
    {
        $traceData = [
            'input' => 'Anonymous trace',
            'output' => 'Response'
        ];

        $message = new FlushLangfuseTrace($traceData);

        $this->mockLogger
            ->expects(self::exactly(2))
            ->method('info')
        ;

        $this->mockSyncFlusher
            ->expects(self::once())
            ->method('flush')
            ->with($traceData, null)
        ;

        $this->handler->__invoke($message);
    }

    public function testHandleMessageWithFlushException(): void
    {
        $traceData = [
            'name' => 'failing-trace'
        ];

        $message = new FlushLangfuseTrace($traceData);
        $exception = new \RuntimeException('Flush failed');

        $this->mockLogger
            ->expects(self::once())
            ->method('info')
            ->with('Processing async trace flush', ['trace_name' => 'failing-trace'])
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('error')
            ->with('Failed to flush trace to Langfuse in async handler', [
                'error' => 'Flush failed',
                'trace_name' => 'failing-trace'
            ])
        ;

        $this->mockSyncFlusher
            ->expects(self::once())
            ->method('flush')
            ->willThrowException($exception)
        ;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Flush failed');

        $this->handler->__invoke($message);
    }

    public function testHandleMessageWithFlushExceptionAndNoTraceName(): void
    {
        $traceData = [
            'input' => 'Some input'
        ];

        $message = new FlushLangfuseTrace($traceData);
        $exception = new \InvalidArgumentException('Invalid trace data');

        $this->mockLogger
            ->expects(self::once())
            ->method('info')
            ->with('Processing async trace flush', ['trace_name' => 'unknown'])
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('error')
            ->with('Failed to flush trace to Langfuse in async handler', [
                'error' => 'Invalid trace data',
                'trace_name' => 'unknown'
            ])
        ;

        $this->mockSyncFlusher
            ->expects(self::once())
            ->method('flush')
            ->willThrowException($exception)
        ;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid trace data');

        $this->handler->__invoke($message);
    }

    public function testHandleComplexTraceData(): void
    {
        $traceData = [
            'name' => 'complex-trace',
            'input' => [
                'messages' => [
                    ['role' => 'user', 'content' => 'Hello'],
                    ['role' => 'assistant', 'content' => 'Hi there!']
                ]
            ],
            'output' => [
                'choices' => [
                    ['message' => ['content' => 'Response']]
                ]
            ],
            'metadata' => [
                'provider' => 'openai',
                'model' => 'gpt-4',
                'temperature' => 0.7
            ],
            'tags' => ['async', 'production']
        ];

        $usage = $this->createMock(Usage::class);
        $message = new FlushLangfuseTrace($traceData, $usage);

        $this->mockLogger
            ->expects(self::exactly(2))
            ->method('info')
        ;

        $this->mockSyncFlusher
            ->expects(self::once())
            ->method('flush')
            ->with($traceData, $usage)
        ;

        $this->handler->__invoke($message);
    }

    public function testConstructorWithNullLogger(): void
    {
        $handler = new FlushLangfuseTraceHandler($this->mockSyncFlusher);

        $traceData = ['name' => 'test'];
        $message = new FlushLangfuseTrace($traceData);

        $this->mockSyncFlusher
            ->expects(self::once())
            ->method('flush')
        ;

        // Should not throw exception with NullLogger
        $handler->__invoke($message);
    }

    public function testHandleEmptyTraceData(): void
    {
        $traceData = [];
        $message = new FlushLangfuseTrace($traceData);

        $this->mockLogger
            ->expects(self::exactly(2))
            ->method('info')
        ;

        $this->mockSyncFlusher
            ->expects(self::once())
            ->method('flush')
            ->with($traceData, null)
        ;

        $this->handler->__invoke($message);
    }

    public function testHandleMessageLogicFlow(): void
    {
        $traceData = ['name' => 'flow-test'];
        $message = new FlushLangfuseTrace($traceData);

        // Test that the flow goes: info -> flush -> info
        $callOrder = [];

        $this->mockLogger
            ->method('info')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'info';
            })
        ;

        $this->mockSyncFlusher
            ->method('flush')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'flush';
            })
        ;

        $this->handler->__invoke($message);

        self::assertEquals(['info', 'flush', 'info'], $callOrder);
    }

    public function testHandleMessagePreservesTraceData(): void
    {
        $originalTraceData = [
            'name' => 'preserve-test',
            'nested' => ['key' => 'value'],
            'array' => [1, 2, 3]
        ];

        $message = new FlushLangfuseTrace($originalTraceData);

        $capturedData = null;
        $this->mockSyncFlusher
            ->expects(self::once())
            ->method('flush')
            ->willReturnCallback(function ($traceData) use (&$capturedData) {
                $capturedData = $traceData;
            })
        ;

        $this->handler->__invoke($message);

        self::assertEquals($originalTraceData, $capturedData);
    }
}

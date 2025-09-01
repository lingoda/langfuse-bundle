<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Client;

use Dropsolid\LangFuse\Client;
use Dropsolid\LangFuse\Observability\Trace;
use Lingoda\LangfuseBundle\Client\TraceClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TraceClientTest extends TestCase
{
    private Client&MockObject $mockClient;
    private LoggerInterface&MockObject $mockLogger;
    private TraceClient $traceClient;

    protected function setUp(): void
    {
        $this->mockClient = $this->createMock(Client::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->traceClient = new TraceClient($this->mockClient, $this->mockLogger);
    }

    public function testGetClient(): void
    {
        $result = $this->traceClient->getClient();
        self::assertSame($this->mockClient, $result);
    }

    public function testCreateTraceSuccess(): void
    {
        $mockTrace = $this->createMock(Trace::class);

        $data = [
            'name' => 'test-trace',
            'userId' => 'user-123',
            'sessionId' => 'session-456',
            'metadata' => ['key' => 'value'],
            'tags' => ['test', 'unit'],
            'version' => '1.0.0',
            'release' => 'production',
            'input' => ['data' => 'input'],
            'output' => ['data' => 'output'],
        ];

        $this->mockClient
            ->expects(self::once())
            ->method('trace')
            ->with(
                'test-trace',
                'user-123',
                'session-456',
                ['key' => 'value'],
                ['test', 'unit'],
                '1.0.0',
                'production',
                ['data' => 'input'],
                ['data' => 'output']
            )
            ->willReturn($mockTrace)
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('debug')
            ->with(
                'Trace created',
                [
                    'name' => 'test-trace',
                    'userId' => 'user-123',
                    'sessionId' => 'session-456',
                    'tags' => ['test', 'unit'],
                ]
            )
        ;

        $result = $this->traceClient->createTrace($data);

        self::assertSame($mockTrace, $result);
    }

    public function testTraceWithMinimalData(): void
    {
        $mockTrace = $this->createMock(Trace::class);

        $data = ['name' => 'minimal-trace'];

        $this->mockClient
            ->expects(self::once())
            ->method('trace')
            ->with(
                'minimal-trace',
                null,
                null,
                null,
                [],
                null,
                null,
                null,
                null
            )
            ->willReturn($mockTrace)
        ;

        $result = $this->traceClient->trace($data);

        self::assertSame($mockTrace, $result);
    }

    public function testTraceWithInvalidDataTypes(): void
    {
        $mockTrace = $this->createMock(Trace::class);

        $data = [
            'name' => 123, // Invalid type, should be cast to string
            'userId' => ['array'], // Invalid type, should be null
            'metadata' => 'string', // Invalid type, should be null
            'tags' => 'not-array', // Invalid type, should be empty array
        ];

        $this->mockClient
            ->expects(self::once())
            ->method('trace')
            ->with(
                'unnamed_trace', // Falls back to default
                null,
                null,
                null,
                [],
                null,
                null,
                null,
                null
            )
            ->willReturn($mockTrace)
        ;

        $result = $this->traceClient->trace($data);

        self::assertSame($mockTrace, $result);
    }

    public function testTraceHandlesException(): void
    {
        $data = ['name' => 'failing-trace'];

        $this->mockClient
            ->expects(self::once())
            ->method('trace')
            ->willThrowException(new \RuntimeException('API error'))
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with(
                'Failed to create trace',
                [
                    'name' => 'failing-trace',
                    'error' => 'API error',
                ]
            )
        ;

        $result = $this->traceClient->trace($data);

        self::assertNull($result);
    }

    public function testUpdateTrace(): void
    {
        $traceId = 'trace-123';
        $updateData = [
            'status' => 'completed',
            'output' => ['result' => 'success'],
        ];

        $this->mockClient
            ->expects(self::once())
            ->method('sendEvent')
            ->with([
                'type' => 'trace-update',
                'traceId' => 'trace-123',
                'data' => $updateData,
            ])
        ;

        $this->mockLogger
            ->expects(self::exactly(2))
            ->method('debug')
            ->willReturnCallback(function ($message, $context) {
                static $callCount = 0;
                $callCount++;

                if ($callCount === 1) {
                    self::assertEquals('Event sent to Langfuse', $message);
                } else {
                    self::assertEquals('Trace updated', $message);
                    self::assertEquals('trace-123', $context['trace_id']);
                    self::assertEquals('completed', $context['status']);
                }
            })
        ;

        $this->traceClient->updateTrace($traceId, $updateData);
    }

    public function testUpdateTraceHandlesException(): void
    {
        $traceId = 'trace-123';
        $updateData = ['status' => 'failed'];

        $this->mockClient
            ->expects(self::once())
            ->method('sendEvent')
            ->willThrowException(new \RuntimeException('Update failed'))
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with(
                'Failed to send event to Langfuse',
                self::callback(
                    fn ($context) =>
                    $context['error'] === 'Update failed' &&
                    $context['event_type'] === 'trace-update'
                )
            )
        ;

        $this->traceClient->updateTrace($traceId, $updateData);
    }

    public function testSendEvent(): void
    {
        $payload = [
            'type' => 'custom-event',
            'name' => 'test-event',
            'data' => ['key' => 'value'],
        ];

        $this->mockClient
            ->expects(self::once())
            ->method('sendEvent')
            ->with($payload)
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('debug')
            ->with(
                'Event sent to Langfuse',
                [
                    'event_type' => 'custom-event',
                    'event_name' => 'test-event',
                ]
            )
        ;

        $this->traceClient->sendEvent($payload);
    }

    public function testSendEventHandlesException(): void
    {
        $payload = ['type' => 'failing-event'];

        $this->mockClient
            ->expects(self::once())
            ->method('sendEvent')
            ->willThrowException(new \RuntimeException('Send failed'))
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with(
                'Failed to send event to Langfuse',
                [
                    'error' => 'Send failed',
                    'event_type' => 'failing-event',
                ]
            )
        ;

        // Should not throw exception
        $this->traceClient->sendEvent($payload);
    }

    public function testFlush(): void
    {
        $this->mockClient
            ->expects(self::once())
            ->method('flush')
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('debug')
            ->with('Flushed traces to Langfuse')
        ;

        $this->traceClient->flush();
    }

    public function testFlushHandlesException(): void
    {
        $this->mockClient
            ->expects(self::once())
            ->method('flush')
            ->willThrowException(new \RuntimeException('Flush failed'))
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with(
                'Failed to flush traces to Langfuse',
                ['error' => 'Flush failed']
            )
        ;

        // Should not throw exception
        $this->traceClient->flush();
    }

    public function testTestConnectionSuccess(): void
    {
        $mockTrace = $this->createMock(Trace::class);

        $this->mockClient
            ->method('trace')
            ->willReturn($mockTrace)
        ;

        $mockTrace
            ->method('end')
        ;

        $this->mockClient
            ->method('flush')
        ;

        $this->mockLogger
            ->method('debug')
        ;

        $this->mockLogger
            ->method('info')
        ;

        $result = $this->traceClient->testConnection();

        self::assertTrue($result);
    }

    public function testTestConnectionFailure(): void
    {
        $this->mockClient
            ->expects(self::once())
            ->method('trace')
            ->willThrowException(new \RuntimeException('Connection error'))
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('error')
            ->with(
                'Langfuse connection test failed',
                ['error' => 'Connection error']
            )
        ;

        $result = $this->traceClient->testConnection();

        self::assertFalse($result);
    }

    public function testWithoutLogger(): void
    {
        $traceClient = new TraceClient($this->mockClient);

        $mockTrace = $this->createMock(Trace::class);

        $this->mockClient
            ->expects(self::once())
            ->method('trace')
            ->willReturn($mockTrace)
        ;

        // Should work without logger (uses NullLogger)
        $result = $traceClient->createTrace(['name' => 'test']);

        self::assertSame($mockTrace, $result);
    }

    public function testTraceWithEmptyName(): void
    {
        $mockTrace = $this->createMock(Trace::class);

        $data = ['name' => ''];

        $this->mockClient
            ->expects(self::once())
            ->method('trace')
            ->with(
                '', // Empty string is preserved
                null,
                null,
                null,
                [],
                null,
                null,
                null,
                null
            )
            ->willReturn($mockTrace)
        ;

        $result = $this->traceClient->trace($data);

        self::assertSame($mockTrace, $result);
    }

    public function testSendEventWithMissingType(): void
    {
        $payload = ['name' => 'event-without-type'];

        $this->mockClient
            ->expects(self::once())
            ->method('sendEvent')
            ->with($payload)
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('debug')
            ->with(
                'Event sent to Langfuse',
                [
                    'event_type' => 'unknown',
                    'event_name' => 'event-without-type',
                ]
            )
        ;

        $this->traceClient->sendEvent($payload);
    }

    public function testUpdateTraceWithoutStatus(): void
    {
        $traceId = 'trace-456';
        $updateData = ['output' => ['result' => 'data']];

        $this->mockClient
            ->expects(self::once())
            ->method('sendEvent')
            ->with([
                'type' => 'trace-update',
                'traceId' => 'trace-456',
                'data' => $updateData,
            ])
        ;

        $this->mockLogger
            ->expects(self::exactly(2))
            ->method('debug')
            ->willReturnCallback(function ($message, $context) {
                static $callCount = 0;
                $callCount++;

                if ($callCount === 1) {
                    self::assertEquals('Event sent to Langfuse', $message);
                } else {
                    self::assertEquals('Trace updated', $message);
                    self::assertEquals('trace-456', $context['trace_id']);
                    self::assertNull($context['status']);
                }
            })
        ;

        $this->traceClient->updateTrace($traceId, $updateData);
    }
}

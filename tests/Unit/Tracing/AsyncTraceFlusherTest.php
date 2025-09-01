<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Tracing;

use Lingoda\AiSdk\Result\Usage;
use Lingoda\LangfuseBundle\Message\FlushLangfuseTrace;
use Lingoda\LangfuseBundle\Tracing\AsyncTraceFlusher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class AsyncTraceFlusherTest extends TestCase
{
    private MessageBusInterface&MockObject $mockMessageBus;
    private LoggerInterface&MockObject $mockLogger;
    private AsyncTraceFlusher $flusher;

    protected function setUp(): void
    {
        $this->mockMessageBus = $this->createMock(MessageBusInterface::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);

        $this->flusher = new AsyncTraceFlusher(
            $this->mockMessageBus,
            $this->mockLogger
        );
    }

    public function testFlushWithoutUsage(): void
    {
        $traceData = [
            'name' => 'test-trace',
            'input' => 'Hello world',
            'output' => 'Hello there!'
        ];

        $expectedTraceData = [
            'name' => 'test-trace',
            'input' => 'Hello world',
            'output' => 'Hello there!',
            'tags' => ['async']
        ];

        $this->mockMessageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (FlushLangfuseTrace $message) => $message->getTraceData() === $expectedTraceData
                    && $message->getUsage() === null))
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('debug')
            ->with('Trace dispatched for async flushing', [
                'trace_name' => 'test-trace'
            ])
        ;

        $this->flusher->flush($traceData);
    }

    public function testFlushWithUsage(): void
    {
        $traceData = [
            'name' => 'completion-trace',
            'model' => 'gpt-4'
        ];

        $usage = $this->createMock(Usage::class);

        $expectedTraceData = [
            'name' => 'completion-trace',
            'model' => 'gpt-4',
            'tags' => ['async']
        ];

        $this->mockMessageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (FlushLangfuseTrace $message) => $message->getTraceData() === $expectedTraceData
                    && $message->getUsage() === $usage))
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('debug')
            ->with('Trace dispatched for async flushing', [
                'trace_name' => 'completion-trace'
            ])
        ;

        $this->flusher->flush($traceData, $usage);
    }

    public function testFlushWithExistingTags(): void
    {
        $traceData = [
            'name' => 'tagged-trace',
            'tags' => ['production', 'important']
        ];

        $expectedTraceData = [
            'name' => 'tagged-trace',
            'tags' => ['production', 'important', 'async']
        ];

        $this->mockMessageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (FlushLangfuseTrace $message) => $message->getTraceData() === $expectedTraceData))
        ;

        $this->flusher->flush($traceData);
    }

    public function testFlushWithDuplicateTags(): void
    {
        $traceData = [
            'name' => 'duplicate-tags-trace',
            'tags' => ['async', 'production', 'async'] // Duplicate 'async' tags
        ];

        $this->mockMessageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (FlushLangfuseTrace $message) {
                $expectedTraceData = [
                    'tags' => ['async', 'production'] // Should remove duplicates
                ];
                $actualTags = $message->getTraceData()['tags'];
                sort($actualTags);
                $expectedTags = $expectedTraceData['tags'];
                sort($expectedTags);

                return $actualTags === $expectedTags;
            }))
        ;

        $this->flusher->flush($traceData);
    }

    public function testFlushWithoutTraceName(): void
    {
        $traceData = [
            'input' => 'Anonymous trace',
            'output' => 'Response'
        ];

        $this->mockMessageBus
            ->expects(self::once())
            ->method('dispatch')
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('debug')
            ->with('Trace dispatched for async flushing', [
                'trace_name' => 'unknown'
            ])
        ;

        $this->flusher->flush($traceData);
    }

    public function testFlushWithMessageBusException(): void
    {
        $traceData = [
            'name' => 'failing-trace'
        ];

        $exception = new \RuntimeException('Message bus failed');

        $this->mockMessageBus
            ->expects(self::once())
            ->method('dispatch')
            ->willThrowException($exception)
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('error')
            ->with('Failed to dispatch trace for async flushing', [
                'error' => 'Message bus failed',
                'trace_name' => 'failing-trace'
            ])
        ;

        // Should not throw exception - silent failure
        $this->flusher->flush($traceData);
    }

    public function testFlushWithMessageBusExceptionAndNoTraceName(): void
    {
        $traceData = [
            'input' => 'Some input'
        ];

        $exception = new \InvalidArgumentException('Invalid message');

        $this->mockMessageBus
            ->expects(self::once())
            ->method('dispatch')
            ->willThrowException($exception)
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('error')
            ->with('Failed to dispatch trace for async flushing', [
                'error' => 'Invalid message',
                'trace_name' => 'unknown'
            ])
        ;

        // Should not throw exception - silent failure
        $this->flusher->flush($traceData);
    }

    public function testFlushWithComplexTraceData(): void
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
            'tags' => ['test']
        ];

        $usage = $this->createMock(Usage::class);

        $expectedTraceData = $traceData;
        $expectedTraceData['tags'] = ['test', 'async'];

        $this->mockMessageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (FlushLangfuseTrace $message) => $message->getTraceData() === $expectedTraceData
                    && $message->getUsage() === $usage))
        ;

        $this->flusher->flush($traceData, $usage);
    }

    public function testConstructorWithNullLogger(): void
    {
        $flusher = new AsyncTraceFlusher($this->mockMessageBus);

        $traceData = ['name' => 'test'];

        $this->mockMessageBus
            ->expects(self::once())
            ->method('dispatch')
        ;

        // Should not throw exception with NullLogger
        $flusher->flush($traceData);
    }

    public function testFlushWithEmptyTraceData(): void
    {
        $traceData = [];

        $expectedTraceData = [
            'tags' => ['async']
        ];

        $this->mockMessageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (FlushLangfuseTrace $message) => $message->getTraceData() === $expectedTraceData))
        ;

        $this->mockLogger
            ->expects(self::once())
            ->method('debug')
            ->with('Trace dispatched for async flushing', [
                'trace_name' => 'unknown'
            ])
        ;

        $this->flusher->flush($traceData);
    }

    public function testFlushPreservesOriginalTraceDataStructure(): void
    {
        $traceData = [
            'name' => 'structure-test',
            'nested' => [
                'level1' => [
                    'level2' => 'deep-value'
                ]
            ],
            'array_data' => ['a', 'b', 'c'],
            'null_value' => null,
            'boolean_value' => false,
            'numeric_value' => 42
        ];

        $this->mockMessageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (FlushLangfuseTrace $message) use ($traceData) {
                $actualData = $message->getTraceData();

                // Should preserve all original data except tags
                unset($actualData['tags']);

                return $actualData === $traceData;
            }))
        ;

        $this->flusher->flush($traceData);
    }
}

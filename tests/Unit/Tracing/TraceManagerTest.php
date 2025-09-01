<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Tracing;

use Lingoda\AiSdk\Prompt\Conversation;
use Lingoda\AiSdk\Prompt\Prompt;
use Lingoda\AiSdk\Result\BinaryResult;
use Lingoda\AiSdk\Result\ObjectResult;
use Lingoda\AiSdk\Result\ResultInterface;
use Lingoda\AiSdk\Result\StreamResult;
use Lingoda\AiSdk\Result\TextResult;
use Lingoda\AiSdk\Result\ToolCall;
use Lingoda\AiSdk\Result\ToolCallResult;
use Lingoda\AiSdk\Result\Usage;
use Lingoda\LangfuseBundle\Tracing\TraceFlusherInterface;
use Lingoda\LangfuseBundle\Tracing\TraceManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class TraceManagerTest extends TestCase
{
    private TraceFlusherInterface&MockObject $mockFlusher;
    private MockClock $clock;
    private TraceManager $traceManager;

    protected function setUp(): void
    {
        $this->mockFlusher = $this->createMock(TraceFlusherInterface::class);
        $this->clock = new MockClock('2024-01-01 12:00:00');

        $this->traceManager = new TraceManager(
            $this->mockFlusher,
            $this->clock,
            'test',
            true,
            1.0
        );
    }

    public function testIsEnabled(): void
    {
        self::assertTrue($this->traceManager->isEnabled());

        $disabledManager = new TraceManager(
            $this->mockFlusher,
            $this->clock,
            'test',
            false,
            1.0
        );

        self::assertFalse($disabledManager->isEnabled());
    }

    public function testTraceWhenDisabled(): void
    {
        $disabledManager = new TraceManager(
            $this->mockFlusher,
            $this->clock,
            'test',
            false,
            1.0
        );

        $this->mockFlusher->expects(self::never())->method('flush');

        $result = new TextResult('test response');
        $callable = fn () => $result;

        $actualResult = $disabledManager->trace(
            'test-operation',
            ['key' => 'value'],
            'test input',
            $callable
        );

        self::assertSame($result, $actualResult);
    }

    public function testTraceWithStringInput(): void
    {
        $result = $this->createMock(TextResult::class);
        $result->method('getContent')->willReturn('test response');
        $result->method('getMetadata')->willReturn(['model' => 'gpt-4']);
        $result->method('getUsage')->willReturn(new Usage(10, 20, 30));

        $callable = function () use ($result) {
            $this->clock->sleep(2.5); // Simulate operation time inside the callable
            return $result;
        };

        $this->mockFlusher
            ->expects(self::once())
            ->method('flush')
            ->with(self::callback(static function ($traceData) {
                self::assertEquals('test-operation', $traceData['name']);
                self::assertEquals(['test'], $traceData['tags']);
                self::assertEquals('test', $traceData['environment']);
                self::assertEquals('gpt-4', $traceData['metadata']['model']);
                self::assertEquals(['type' => 'string', 'content' => 'test input'], $traceData['input']);
                self::assertEquals(['type' => 'text', 'content' => 'test response'], $traceData['output']);
                self::assertEquals('success', $traceData['status']);
                self::assertIsFloat($traceData['duration']);
                self::assertEquals(2.5, $traceData['duration']);
                self::assertArrayHasKey('timestamp', $traceData['metadata']);
                self::assertEquals(['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30], $traceData['usage']);
                return true;
            }), self::isInstanceOf(Usage::class))
        ;

        $actualResult = $this->traceManager->trace(
            'test-operation',
            ['initial' => 'metadata'],
            'test input',
            $callable
        );

        self::assertSame($result, $actualResult);
    }

    public function testTraceWithPromptInput(): void
    {
        $prompt = $this->createMock(Prompt::class);
        $prompt->method('toArray')->willReturn(['role' => 'user', 'content' => 'test prompt']);

        $result = $this->createMock(TextResult::class);
        $result->method('getContent')->willReturn('response');
        $result->method('getMetadata')->willReturn([]);
        $result->method('getUsage')->willReturn(null);

        $callable = fn () => $result;

        $this->mockFlusher
            ->expects(self::once())
            ->method('flush')
            ->with(self::callback(static function ($traceData) use ($prompt) {
                self::assertEquals([
                    'type' => 'object',
                    'class' => $prompt::class,
                    'content' => ['role' => 'user', 'content' => 'test prompt']
                ], $traceData['input']);
                return true;
            }), null)
        ;

        $this->traceManager->trace('test', [], $prompt, $callable);
    }

    public function testTraceWithConversationInput(): void
    {
        $conversation = $this->createMock(Conversation::class);
        $conversation->method('toArray')->willReturn([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there!']
        ]);

        $result = $this->createMock(TextResult::class);
        $result->method('getContent')->willReturn('response');
        $result->method('getMetadata')->willReturn([]);
        $result->method('getUsage')->willReturn(null);

        $callable = fn () => $result;

        $this->mockFlusher
            ->expects(self::once())
            ->method('flush')
            ->with(self::callback(static function ($traceData) use ($conversation) {
                self::assertEquals([
                    'type' => 'object',
                    'class' => $conversation::class,
                    'content' => [
                        ['role' => 'user', 'content' => 'Hello'],
                        ['role' => 'assistant', 'content' => 'Hi there!']
                    ]
                ], $traceData['input']);
                return true;
            }), null)
        ;

        $this->traceManager->trace('conversation', [], $conversation, $callable);
    }

    public function testTraceWithException(): void
    {
        $exception = new \RuntimeException('Operation failed');
        $callable = function () use ($exception) {
            throw $exception;
        };

        $this->mockFlusher
            ->expects(self::once())
            ->method('flush')
            ->with(self::callback(static function ($traceData) {
                self::assertEquals('error', $traceData['status']);
                self::assertEquals('Operation failed', $traceData['error']);
                self::assertArrayNotHasKey('output', $traceData);
                return true;
            }), null)
        ;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Operation failed');

        $this->traceManager->trace('failing-op', [], 'input', $callable);
    }

    public function testTraceWithBinaryResult(): void
    {
        $result = $this->createMock(BinaryResult::class);
        $result->method('getMimeType')->willReturn('audio/mpeg');
        $result->method('getContent')->willReturn('binary-data-here');
        $result->method('getMetadata')->willReturn([]);
        $result->method('getUsage')->willReturn(null);

        $callable = fn () => $result;

        $this->mockFlusher
            ->expects(self::once())
            ->method('flush')
            ->with(self::callback(static function ($traceData) {
                self::assertEquals([
                    'type' => 'binary',
                    'mime_type' => 'audio/mpeg',
                    'size' => 16
                ], $traceData['output']);
                return true;
            }), null)
        ;

        $this->traceManager->trace('binary-op', [], 'input', $callable);
    }

    public function testTraceWithStreamResult(): void
    {
        $result = $this->createMock(StreamResult::class);
        $result->method('getMimeType')->willReturn('text/event-stream');
        $result->method('getMetadata')->willReturn([]);
        $result->method('getUsage')->willReturn(null);

        $callable = fn () => $result;

        $this->mockFlusher
            ->expects(self::once())
            ->method('flush')
            ->with(self::callback(static function ($traceData) {
                self::assertEquals([
                    'type' => 'stream',
                    'mime_type' => 'text/event-stream'
                ], $traceData['output']);
                return true;
            }), null)
        ;

        $this->traceManager->trace('stream-op', [], 'input', $callable);
    }

    public function testTraceWithObjectResult(): void
    {
        $result = $this->createMock(ObjectResult::class);
        $result->method('getContent')->willReturn((object)['key' => 'value', 'nested' => (object)['data' => 123]]);
        $result->method('getMetadata')->willReturn([]);
        $result->method('getUsage')->willReturn(null);

        $callable = fn () => $result;

        $this->mockFlusher
            ->expects(self::once())
            ->method('flush')
            ->with(self::callback(static function ($traceData) {
                self::assertEquals([
                    'type' => 'object',
                    'data' => (object)['key' => 'value', 'nested' => (object)['data' => 123]]
                ], $traceData['output']);
                return true;
            }), null)
        ;

        $this->traceManager->trace('object-op', [], 'input', $callable);
    }

    public function testTraceWithToolCallResult(): void
    {
        $toolCall1 = $this->createMock(ToolCall::class);
        $toolCall1->method('getName')->willReturn('search');
        $toolCall1->method('getArguments')->willReturn(['query' => 'test']);

        $toolCall2 = $this->createMock(ToolCall::class);
        $toolCall2->method('getName')->willReturn('calculate');
        $toolCall2->method('getArguments')->willReturn(['expression' => '2+2']);

        $result = $this->createMock(ToolCallResult::class);
        $result->method('getContent')->willReturn([$toolCall1, $toolCall2]);
        $result->method('getMetadata')->willReturn([]);
        $result->method('getUsage')->willReturn(null);

        $callable = fn () => $result;

        $this->mockFlusher
            ->expects(self::once())
            ->method('flush')
            ->with(self::callback(static function ($traceData) {
                self::assertEquals([
                    'type' => 'tool_call',
                    'tools' => [
                        ['name' => 'search', 'arguments' => ['query' => 'test']],
                        ['name' => 'calculate', 'arguments' => ['expression' => '2+2']]
                    ]
                ], $traceData['output']);
                return true;
            }), null)
        ;

        $this->traceManager->trace('tool-op', [], 'input', $callable);
    }

    public function testTraceWithSamplingRateZero(): void
    {
        $sampledManager = new TraceManager(
            $this->mockFlusher,
            $this->clock,
            'test',
            true,
            0.0
        );

        $this->mockFlusher->expects(self::never())->method('flush');

        $result = new TextResult('test response');
        $callable = fn () => $result;

        $actualResult = $sampledManager->trace('test', [], 'input', $callable);

        self::assertSame($result, $actualResult);
    }

    public function testTraceWithPartialSamplingRate(): void
    {
        // This test is probabilistic, so we test the boundaries
        $halfSampledManager = new TraceManager(
            $this->mockFlusher,
            $this->clock,
            environment: 'test',
            enabled: true,
            samplingRate: 0.5
        );

        $result = new TextResult('test response');
        $callable = fn () => $result;

        // Run multiple times to ensure sampling works
        $tracedCount = 0;
        $totalRuns = 100;

        for ($i = 0; $i < $totalRuns; $i++) {
            $mockFlusher = $this->createMock(TraceFlusherInterface::class);
            $manager = new TraceManager(
                $mockFlusher,
                $this->clock,
                'test',
                true,
                0.5
            );

            // Set up expectation tracking
            $traced = false;
            $mockFlusher->method('flush')->willReturnCallback(function () use (&$traced) {
                $traced = true;
            });

            $manager->trace('test', [], 'input', $callable);

            if ($traced) {
                $tracedCount++;
            }
        }

        // With 50% sampling rate, we expect roughly 50% to be traced
        // Allow for statistical variance (30-70% range)
        self::assertGreaterThan(30, $tracedCount);
        self::assertLessThan(70, $tracedCount);
    }

    public function testTracePreservesMetadata(): void
    {
        $result = $this->createMock(TextResult::class);
        $result->method('getContent')->willReturn('response');
        $result->method('getMetadata')->willReturn(['model' => 'gpt-4-override']);
        $result->method('getUsage')->willReturn(null);

        $callable = fn () => $result;

        $metadata = [
            'user_id' => '123',
            'session_id' => 'abc',
            'model' => 'gpt-3.5', // This should be overridden by result metadata
            'custom_field' => 'value'
        ];

        $this->mockFlusher
            ->expects(self::once())
            ->method('flush')
            ->with(self::callback(static function ($traceData) {
                self::assertEquals('123', $traceData['metadata']['user_id']);
                self::assertEquals('abc', $traceData['metadata']['session_id']);
                self::assertEquals('gpt-4-override', $traceData['metadata']['model']); // Overridden
                self::assertEquals('value', $traceData['metadata']['custom_field']);
                self::assertArrayHasKey('timestamp', $traceData['metadata']);
                return true;
            }), null)
        ;

        $this->traceManager->trace('test', $metadata, 'input', $callable);
    }

    public function testTraceWithUnknownResultType(): void
    {
        // Create a custom result type that's not explicitly handled
        $result = new class() implements ResultInterface {
            public function getContent(): string
            {
                return 'custom content';
            }

            public function getMetadata(): array
            {
                return [];
            }

            public function getUsage(): ?Usage
            {
                return null;
            }

            public function withUsage(?Usage $usage): static
            {
                return $this;
            }
        };

        $callable = fn () => $result;

        $this->mockFlusher
            ->expects(self::once())
            ->method('flush')
            ->with(self::callback(static function ($traceData) use ($result) {
                self::assertEquals([
                    'type' => $result::class,
                    'content' => 'custom content'
                ], $traceData['output']);
                return true;
            }), null)
        ;

        $this->traceManager->trace('custom-op', [], 'input', $callable);
    }

    public function testTraceMeasuresDurationAccurately(): void
    {
        $result = $this->createMock(TextResult::class);
        $result->method('getContent')->willReturn('response');
        $result->method('getMetadata')->willReturn([]);
        $result->method('getUsage')->willReturn(null);

        $callable = function () use ($result) {
            // Simulate operation taking time
            $this->clock->sleep(3.75);
            return $result;
        };

        $this->mockFlusher
            ->expects(self::once())
            ->method('flush')
            ->with(self::callback(static function ($traceData) {
                self::assertEquals(3.75, $traceData['duration']);
                return true;
            }), null)
        ;

        $this->traceManager->trace('timed-op', [], 'input', $callable);
    }
}

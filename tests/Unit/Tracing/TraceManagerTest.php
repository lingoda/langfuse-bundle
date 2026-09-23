<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Tracing;

use Lingoda\AiSdk\Decision\Answer;
use Lingoda\AiSdk\Decision\DecisionResult;
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

    public function testTraceWithStructuredRequestInput(): void
    {
        $traced = $this->captureTrace();

        $this->traceManager->trace('typesafe-system-one', [], ['state' => 'text', 'questions' => ['q' => ['type' => 'noul']]], static fn () => new TextResult('ok'));

        self::assertSame(['type' => 'request', 'content' => ['state' => 'text', 'questions' => ['q' => ['type' => 'noul']]]], $traced->data['input']);
    }

    public function testTraceWithDecisionResult(): void
    {
        $traced = $this->captureTrace();
        $result = (new DecisionResult([
            'refund' => Answer::fromArray('refund', ['type' => 'noul', 'noul' => 0.98]),
            'topic' => Answer::fromArray('topic', ['type' => 'choice', 'choice' => 'refund', 'probabilities' => ['refund' => 0.94, 'other' => 0.06], 'confidence' => 0.92]),
            'mood' => Answer::fromArray('mood', ['type' => 'score', 'score' => 1.97, 'probabilities' => [0.0, 0.03, 0.97], 'legend' => ['calm', 'unhappy', 'angry'], 'confidence' => 0.97]),
        ], ['model' => 'jev-1.13.0']))->withUsage(new Usage(565, 0, 565));

        $this->traceManager->trace('typesafe-system-one', [], ['state' => 'text'], static fn () => $result);

        self::assertSame([
            'type' => 'decision',
            'answers' => [
                'refund' => ['type' => 'noul', 'probability' => 0.98],
                'topic' => ['type' => 'choice', 'choice' => 'refund', 'probabilities' => ['refund' => 0.94, 'other' => 0.06], 'confidence' => 0.92],
                'mood' => ['type' => 'score', 'score' => 1.97, 'legend' => ['calm', 'unhappy', 'angry'], 'probabilities' => [0.0, 0.03, 0.97], 'confidence' => 0.97],
            ],
        ], $traced->data['output']);
        self::assertSame('jev-1.13.0', $traced->data['metadata']['model']);
        self::assertSame(565, $traced->usage?->promptTokens);
    }

    public function testTraceWithoutContentKeepsOnlyNonContentFields(): void
    {
        $traced = $this->captureTrace();
        $result = (new TextResult('LNG-4711', ['model' => 'eu.amazon.nova-2-lite-v1:0']))->withUsage(new Usage(900, 5, 905));

        $this->traceManager->trace('mnr-voucher', ['provider' => 'AWS Bedrock'], 'Voucher for Jane Doe', static fn () => $result, false);

        self::assertSame(['type' => 'redacted'], $traced->data['input']);
        self::assertArrayNotHasKey('output', $traced->data);
        self::assertSame('eu.amazon.nova-2-lite-v1:0', $traced->data['metadata']['model']);
        self::assertSame(905, $traced->data['usage']['total_tokens'] ?? null);
        self::assertSame('success', $traced->data['status']);
    }

    public function testTraceWithoutContentRecordsOnlyTheExceptionClass(): void
    {
        $traced = $this->captureTrace();

        try {
            $this->traceManager->trace('mnr-voucher', [], 'Voucher for Jane Doe', static fn () => throw new \RuntimeException('Invalid field "Jane Doe"'), false);
        } catch (\RuntimeException) {
        }

        self::assertSame(\RuntimeException::class, $traced->data['error']);
        self::assertSame(['type' => 'redacted'], $traced->data['input']);
    }

    public function testBinaryResultSizeIsInBytes(): void
    {
        $traced = $this->captureTrace();

        $this->traceManager->trace('text-to-speech', [], 'x', static fn () => new BinaryResult("\u{00E9}\u{00FC}", 'audio/mpeg'));

        self::assertSame(4, $traced->data['output']['size'] ?? null);
    }

    /**
     * Records what reaches the flusher.
     */
    private function captureTrace(): \stdClass
    {
        $traced = new \stdClass();
        $traced->data = [];
        $traced->usage = null;
        $this->mockFlusher->method('flush')->willReturnCallback(static function (array $data, ?Usage $usage) use ($traced): void {
            $traced->data = $data;
            $traced->usage = $usage;
        });

        return $traced;
    }

    public function testTraceIdsAreFixedWhenTheTraceIsBuilt(): void
    {
        $traced = $this->captureTrace();

        $this->traceManager->trace('ai-completion', [], 'x', static fn () => new TextResult('ok'));

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $traced->data['trace_id'] ?? '');
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $traced->data['span_id'] ?? '');
        self::assertSame((float) $this->clock->now()->format('U.u'), $traced->data['started_at'] ?? null);
    }

    public function testFailedCallKeepsTheRequestedModel(): void
    {
        $traced = $this->captureTrace();

        try {
            $this->traceManager->trace('ai-completion', ['model' => 'gpt-4o'], 'x', static fn () => throw new \RuntimeException('Rate limit exceeded'));
        } catch (\RuntimeException) {
        }

        // A failed call keeps the model the decorator requested, so it is still flushed as a generation
        self::assertSame('gpt-4o', $traced->data['metadata']['model']);
        self::assertSame('Rate limit exceeded', $traced->data['error']);
    }
}

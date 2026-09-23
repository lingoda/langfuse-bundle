<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Tracing;

use Dropsolid\LangFuse\Observability\Generation;
use Dropsolid\LangFuse\Observability\Trace;
use Lingoda\AiSdk\Result\TokenDetails;
use Lingoda\AiSdk\Result\Usage;
use Lingoda\LangfuseBundle\Client\TraceClient;
use Lingoda\LangfuseBundle\Tracing\SyncTraceFlusher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SyncTraceFlusherTest extends TestCase
{
    private TraceClient&MockObject $traceClient;
    private LoggerInterface&MockObject $logger;
    private Trace&MockObject $trace;
    private Generation&MockObject $generation;
    private SyncTraceFlusher $flusher;

    protected function setUp(): void
    {
        $this->traceClient = $this->createMock(TraceClient::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->trace = $this->createMock(Trace::class);
        $this->generation = $this->createMock(Generation::class);
        $this->generation->method('withLevel')->willReturnSelf();
        $this->generation->method('withStatusMessage')->willReturnSelf();
        $this->generation->method('withPrompt')->willReturnSelf();
        $this->generation->method('withUsageDetails')->willReturnSelf();
        $this->flusher = new SyncTraceFlusher($this->traceClient, $this->logger);
    }

    public function testTraceWithoutModelKeepsItsOwnOutput(): void
    {
        $traceData = $this->traceData(['metadata' => ['operation_type' => 'basic']]);

        $this->traceClient->expects(self::once())->method('createOrFail')->with($traceData)->willReturn($this->trace);
        $this->trace->expects(self::never())->method('createGeneration');
        $this->trace->expects(self::once())->method('end')->with([
            'metadata' => ['status' => 'success'],
            'output' => ['type' => 'text', 'content' => 'AI response'],
        ]);
        $this->traceClient->expects(self::once())->method('flushOrFail');

        $this->flusher->flush($traceData);
    }

    public function testGenerationCarriesInputOutputUsageAndModelParameters(): void
    {
        $traceData = $this->traceData(['metadata' => ['model' => 'gpt-4o', 'provider' => 'OpenAI', 'temperature' => 0.2]]);

        $this->traceClient->method('createOrFail')->willReturn($this->trace);
        $this->trace->expects(self::once())
            ->method('createGeneration')
            ->with('ai-completion', 'gpt-4o', ['temperature' => 0.2], $traceData['metadata'], $traceData['input'])
            ->willReturn($this->generation)
        ;
        $this->generation->expects(self::once())
            ->method('withUsageDetails')
            ->with(['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30])
        ;
        $this->generation->expects(self::never())->method('withPrompt');
        $this->generation->expects(self::never())->method('withLevel');
        $this->generation->expects(self::once())->method('end')->with(['output' => $traceData['output']]);
        // The generation holds the output, so the trace does not repeat it
        $this->trace->expects(self::once())->method('end')->with(['metadata' => ['status' => 'success']]);

        $this->flusher->flush($traceData, new Usage(10, 20, 30));
    }

    public function testCachedAndReasoningTokensAreSentNested(): void
    {
        $this->traceClient->method('createOrFail')->willReturn($this->trace);
        $this->trace->method('createGeneration')->willReturn($this->generation);
        $this->generation->expects(self::once())->method('withUsageDetails')->with([
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 150,
            'prompt_tokens_details' => ['cached_tokens' => 40],
            'completion_tokens_details' => ['reasoning_tokens' => 30],
        ]);

        $usage = new Usage(100, 50, 150, completionDetails: new TokenDetails(reasoningTokens: 30), cachedTokens: 40);
        $this->flusher->flush($this->traceData(['metadata' => ['model' => 'o3']]), $usage);
    }

    public function testEmptyUsageIsNotSent(): void
    {
        $this->traceClient->method('createOrFail')->willReturn($this->trace);
        $this->trace->method('createGeneration')->willReturn($this->generation);
        $this->generation->expects(self::never())->method('withUsageDetails');

        $this->flusher->flush($this->traceData(['metadata' => ['model' => 'gpt-4o']]), new Usage(0, 0, 0));
    }

    public function testGenerationIsLinkedToItsLangfusePrompt(): void
    {
        $this->traceClient->method('createOrFail')->willReturn($this->trace);
        $this->trace->method('createGeneration')->willReturn($this->generation);
        $this->generation->expects(self::once())->method('withPrompt')->with('mnr-voucher-fields', 3);

        $this->flusher->flush($this->traceData(['metadata' => [
            'model' => 'eu.amazon.nova-2-lite-v1:0',
            'langfuse_prompt' => ['name' => 'mnr-voucher-fields', 'version' => 3],
        ]]));
    }

    public function testFailedGenerationIsMarkedAsError(): void
    {
        $traceData = $this->traceData(['metadata' => ['model' => 'gpt-4o'], 'status' => 'error', 'error' => 'Rate limit exceeded']);
        unset($traceData['output']);

        $this->traceClient->method('createOrFail')->willReturn($this->trace);
        $this->trace->method('createGeneration')->willReturn($this->generation);
        $this->generation->expects(self::once())->method('withLevel')->with('ERROR');
        $this->generation->expects(self::once())->method('withStatusMessage')->with('Rate limit exceeded');
        $this->generation->expects(self::once())->method('end')->with(null);
        $this->trace->expects(self::once())->method('end')->with(['metadata' => ['status' => 'error', 'error' => 'Rate limit exceeded']]);

        $this->flusher->flush($traceData);
    }

    public function testSendThrowsWhenLangfuseFails(): void
    {
        $this->traceClient->method('createOrFail')->willReturn($this->trace);
        $this->traceClient->method('flushOrFail')->willThrowException(new \RuntimeException('Langfuse unreachable'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Langfuse unreachable');

        $this->flusher->send($this->traceData());
    }

    public function testFlushSwallowsAndLogsFailures(): void
    {
        $this->traceClient->method('createOrFail')->willThrowException(new \RuntimeException('Langfuse unreachable'));
        $this->logger->expects(self::once())
            ->method('error')
            ->with('Failed to flush trace to Langfuse', ['error' => 'Langfuse unreachable', 'trace_name' => 'ai-completion'])
        ;

        $this->flusher->flush($this->traceData());
    }

    public function testSuccessIsLoggedAtDebugLevel(): void
    {
        $this->traceClient->method('createOrFail')->willReturn($this->trace);
        $this->logger->expects(self::once())
            ->method('debug')
            ->with('Trace flushed to Langfuse', [
                'trace_name' => 'ai-completion',
                'status' => 'success',
                'has_generation' => false,
                'has_usage' => false,
            ])
        ;

        $this->flusher->flush($this->traceData());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{name: string, tags: array<int, string>, environment: string, metadata: array<string, mixed>, input: array{type: string, content?: array<mixed>|string, class?: string}, duration: float, status: 'error'|'success', output?: array{type: string, content?: mixed}, error?: string}
     */
    private function traceData(array $overrides = []): array
    {
        /** @var array{name: string, tags: array<int, string>, environment: string, metadata: array<string, mixed>, input: array{type: string, content?: array<mixed>|string, class?: string}, duration: float, status: 'error'|'success', output?: array{type: string, content?: mixed}, error?: string} $data */
        $data = array_merge([
            'name' => 'ai-completion',
            'tags' => ['test'],
            'environment' => 'test',
            'metadata' => [],
            'input' => ['type' => 'string', 'content' => 'prompt'],
            'duration' => 0.5,
            'status' => 'success',
            'output' => ['type' => 'text', 'content' => 'AI response'],
        ], $overrides);

        return $data;
    }
}

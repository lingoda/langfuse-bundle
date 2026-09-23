<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Tracing;

use Lingoda\AiSdk\Result\Usage;
use Lingoda\LangfuseBundle\Client\OtlpTraceExporter;
use Lingoda\LangfuseBundle\Exception\LangfuseException;
use Lingoda\LangfuseBundle\Tracing\SyncTraceFlusher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SyncTraceFlusherTest extends TestCase
{
    private OtlpTraceExporter&MockObject $exporter;
    private LoggerInterface&MockObject $logger;
    private SyncTraceFlusher $flusher;

    protected function setUp(): void
    {
        $this->exporter = $this->createMock(OtlpTraceExporter::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->flusher = new SyncTraceFlusher($this->exporter, $this->logger);
    }

    public function testSendExportsTheTraceAndLogsIt(): void
    {
        $traceData = $this->traceData(['metadata' => ['model' => 'gpt-4o']]);
        $usage = new Usage(10, 20, 30);

        $this->exporter->expects(self::once())->method('export')->with($traceData, $usage);
        $this->logger->expects(self::once())
            ->method('debug')
            ->with('Trace flushed to Langfuse', [
                'trace_name' => 'ai-completion',
                'status' => 'success',
                'has_generation' => true,
                'has_usage' => true,
            ])
        ;

        $this->flusher->send($traceData, $usage);
    }

    public function testSendThrowsWhenTheExportFails(): void
    {
        $this->exporter->method('export')->willThrowException(new LangfuseException('Langfuse trace export failed (HTTP 503)'));

        $this->expectException(LangfuseException::class);

        $this->flusher->send($this->traceData());
    }

    public function testFlushSwallowsAndLogsFailures(): void
    {
        $this->exporter->method('export')->willThrowException(new LangfuseException('Langfuse unreachable'));
        $this->logger->expects(self::once())
            ->method('error')
            ->with('Failed to flush trace to Langfuse', ['error' => 'Langfuse unreachable', 'trace_name' => 'ai-completion'])
        ;

        $this->flusher->flush($this->traceData());
    }

    public function testFlushExportsWithoutUsage(): void
    {
        $this->exporter->expects(self::once())->method('export')->with(self::anything(), null);
        $this->logger->expects(self::once())
            ->method('debug')
            ->with('Trace flushed to Langfuse', self::callback(static fn (array $context): bool => $context['has_generation'] === false && $context['has_usage'] === false))
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

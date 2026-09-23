<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tracing;

use Lingoda\AiSdk\Result\Usage;
use Lingoda\LangfuseBundle\Client\OtlpTraceExporter;
use Lingoda\LangfuseBundle\Exception\LangfuseException;
use Lingoda\LangfuseBundle\PhpStan\Types;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Random\RandomException;

/**
 * Synchronous trace flushing service.
 * Sends traces directly to Langfuse (OpenTelemetry v4 ingestion) in the same request.
 *
 * @phpstan-import-type TraceData from Types
 */
final readonly class SyncTraceFlusher implements TraceFlusherInterface
{
    public function __construct(
        private OtlpTraceExporter $exporter,
        private LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * Sends the trace and swallows any failure: tracing must never break the application.
     *
     * @param TraceData $traceData
     */
    public function flush(array $traceData, ?Usage $usage = null): void
    {
        try {
            $this->send($traceData, $usage);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to flush trace to Langfuse', [
                'error' => $e->getMessage(),
                'trace_name' => $traceData['name'],
            ]);
        }
    }

    /**
     * Sends the trace and throws when it cannot, so the async handler can hand the message back for a retry.
     *
     * @param TraceData $traceData
     *
     * @throws LangfuseException|RandomException
     */
    public function send(array $traceData, ?Usage $usage = null): void
    {
        $this->exporter->export($traceData, $usage);

        $this->logger->debug('Trace flushed to Langfuse', [
            'trace_name' => $traceData['name'],
            'status' => $traceData['status'],
            'has_generation' => is_string($traceData['metadata']['model'] ?? null),
            'has_usage' => $usage !== null,
        ]);
    }
}

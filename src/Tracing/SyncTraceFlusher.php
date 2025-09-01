<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tracing;

use Lingoda\AiSdk\Result\Usage;
use Lingoda\LangfuseBundle\Client\TraceClient;
use Lingoda\LangfuseBundle\PhpStan\Types;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Synchronous trace flushing service.
 * Sends traces directly to Langfuse in the same request.
 *
 * @phpstan-import-type TraceData from Types
 */
final readonly class SyncTraceFlusher implements TraceFlusherInterface
{
    public function __construct(
        private TraceClient $traceClient,
        private LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * Flush trace data to Langfuse synchronously.
     *
     * @param TraceData $traceData Trace details including name, metadata, input, output, error, status
     */
    public function flush(array $traceData, ?Usage $usage = null): void
    {
        try {
            // Create a trace
            $trace = $this->traceClient->trace($traceData);

            if ($trace === null) {
                $this->logger->warning('Failed to create trace', [
                    'trace_name' => $traceData['name'] ?? 'unknown',
                ]);
                return;
            }

            // Check if this is an AI generation (has model in metadata)
            if (isset($traceData['metadata']['model']) && is_string($traceData['metadata']['model'])) {
                // Create a generation within the trace for AI operations
                $generation = $trace->createGeneration(
                    name: $traceData['name'] ?? 'ai-completion',
                    model: $traceData['metadata']['model'],
                    modelParameters: isset($traceData['metadata']['temperature'])
                        ? ['temperature' => $traceData['metadata']['temperature']]
                        : null,
                    metadata: $traceData['metadata'] ?? null,
                    input: $traceData['input'] ?? null
                );

                // Set usage details if available
                if ($usage !== null) {
                    $usageDetails = [
                        'prompt_tokens' => $usage->promptTokens,
                        'completion_tokens' => $usage->completionTokens,
                        'total_tokens' => $usage->totalTokens,
                    ];

                    // Filter out zero/null values
                    $usageDetails = array_filter($usageDetails, static fn ($v) => $v > 0);

                    if (!empty($usageDetails)) {
                        $generation->withUsageDetails($usageDetails);
                    }
                }

                // End the generation with output
                $endData = [];

                if (isset($traceData['output'])) {
                    $endData['output'] = $traceData['output'];
                }

                if (isset($traceData['error'])) {
                    $endData['error'] = $traceData['error'];
                    $endData['level'] = 'ERROR';
                }

                if (!empty($endData)) {
                    $generation->end($endData);
                    $this->logger->debug('Generation ended successfully', [
                        'model' => $traceData['metadata']['model'],
                        'usage' => $usageDetails ?? null,
                    ]);
                }
            }

            // End the trace
            $traceEndData = [];

            if (isset($traceData['output']) && !isset($traceData['metadata']['model'])) {
                // Only set output on trace if it's not an AI generation
                $traceEndData['output'] = $traceData['output'];
            }

            if (isset($traceData['error'])) {
                $traceEndData['error'] = $traceData['error'];
                $traceEndData['level'] = 'ERROR';
            }

            if (isset($traceData['status'])) {
                $traceEndData['status'] = $traceData['status'];
            }

            $trace->end($traceEndData);

            // Flush to Langfuse
            $this->traceClient->flush();

            $this->logger->debug('Trace flushed synchronously to Langfuse', [
                'trace_name' => $traceData['name'] ?? 'unknown',
                'status' => $traceData['status'] ?? 'unknown',
                'has_generation' => isset($traceData['metadata']['model']),
                'has_usage' => $usage !== null,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to flush trace to Langfuse', [
                'error' => $e->getMessage(),
                'trace_name' => $traceData['name'] ?? 'unknown',
            ]);
            // Silently fail - tracing should not break the application
        }
    }
}

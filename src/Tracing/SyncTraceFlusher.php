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
     * @throws \Throwable
     */
    public function send(array $traceData, ?Usage $usage = null): void
    {
        $trace = $this->traceClient->createOrFail($traceData);
        $model = $traceData['metadata']['model'] ?? null;
        $error = $traceData['error'] ?? null;

        // A result with a model is an AI generation: it carries the output, usage and prompt link
        if (is_string($model)) {
            $generation = $trace->createGeneration(
                name: $traceData['name'],
                model: $model,
                modelParameters: isset($traceData['metadata']['temperature']) ? ['temperature' => $traceData['metadata']['temperature']] : null,
                metadata: $traceData['metadata'],
                input: $traceData['input'],
            );

            $prompt = $traceData['metadata']['langfuse_prompt'] ?? null;
            if (is_array($prompt) && is_string($prompt['name'] ?? null) && is_int($prompt['version'] ?? null)) {
                $generation->withPrompt($prompt['name'], $prompt['version']);
            }

            $usageDetails = $usage !== null ? self::usageDetails($usage) : null;
            if ($usageDetails !== null) {
                $generation->withUsageDetails($usageDetails);
            }

            if ($error !== null) {
                $generation->withLevel('ERROR')->withStatusMessage($error);
            }

            $generation->end(isset($traceData['output']) ? ['output' => $traceData['output']] : null);
        }

        $traceEnd = ['metadata' => ['status' => $traceData['status']] + ($error !== null ? ['error' => $error] : [])];
        if ($model === null && isset($traceData['output'])) {
            $traceEnd['output'] = $traceData['output'];
        }
        $trace->end($traceEnd);

        $this->traceClient->flushOrFail();

        $this->logger->debug('Trace flushed to Langfuse', [
            'trace_name' => $traceData['name'],
            'status' => $traceData['status'],
            'has_generation' => is_string($model),
            'has_usage' => $usage !== null,
        ]);
    }

    /**
     * The OpenAI usage shape, which Langfuse accepts with nested cached and reasoning token counts.
     *
     * @return array<string, int|array<string, int>>|null
     */
    private static function usageDetails(Usage $usage): ?array
    {
        if ($usage->promptTokens <= 0 && $usage->completionTokens <= 0 && $usage->totalTokens <= 0) {
            return null;
        }

        $details = [
            'prompt_tokens' => max(0, $usage->promptTokens),
            'completion_tokens' => max(0, $usage->completionTokens),
            'total_tokens' => max(0, $usage->totalTokens),
        ];

        $cached = $usage->cachedTokens ?? $usage->promptDetails?->cachedTokens;
        if ($cached !== null) {
            $details['prompt_tokens_details'] = ['cached_tokens' => max(0, $cached)];
        }

        $reasoning = $usage->reasoningTokens ?? $usage->completionDetails?->reasoningTokens;
        if ($reasoning !== null) {
            $details['completion_tokens_details'] = ['reasoning_tokens' => max(0, $reasoning)];
        }

        return $details;
    }
}

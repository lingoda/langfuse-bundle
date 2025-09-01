<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tracing;

use Lingoda\AiSdk\Prompt\Conversation;
use Lingoda\AiSdk\Prompt\Prompt;
use Lingoda\AiSdk\Result\BinaryResult;
use Lingoda\AiSdk\Result\ObjectResult;
use Lingoda\AiSdk\Result\ResultInterface;
use Lingoda\AiSdk\Result\StreamResult;
use Lingoda\AiSdk\Result\TextResult;
use Lingoda\AiSdk\Result\ToolCall;
use Lingoda\AiSdk\Result\ToolCallResult;
use Lingoda\LangfuseBundle\PhpStan\Types;
use Symfony\Component\Clock\ClockInterface;

/**
 * Focused trace manager for AI operations.
 * Handles trace data preparation and delegates flushing to dedicated services.
 *
 * @phpstan-import-type TraceData from Types
 * @phpstan-import-type InputData from Types
 * @phpstan-import-type OutputData from Types
 */
final readonly class TraceManager implements TraceManagerInterface
{
    public function __construct(
        private TraceFlusherInterface $flushService,
        private ClockInterface $clock,
        private string $environment,
        private bool $enabled = true,
        private float $samplingRate = 1.0
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @throws \Throwable
     */
    public function trace(string $name, array $metadata, string|Prompt|Conversation $input, callable $callable): ResultInterface
    {
        if (!$this->shouldTrace()) {
            return $callable();
        }

        $metadata['timestamp'] = $this->clock->now()->format(\DateTimeInterface::ATOM);

        $startTime = $this->clock->now();
        $error = null;
        $result = null;

        try {
            $result = $callable();

            return $result;
        } catch (\Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            $duration = $this->calculateDuration($startTime);
            $traceData = $this->buildTraceData($name, $metadata, $input, $result, $duration, $error);

            // Delegate to flush service (sync or async)
            $usage = $result instanceof ResultInterface ? $result->getUsage() : null;
            $this->flushService->flush($traceData, $usage);
        }
    }

    /**
     * Calculate duration from start time.
     */
    private function calculateDuration(\DateTimeImmutable $startTime): float
    {
        $endTime = $this->clock->now();

        return (float) $endTime->format('U.u') - (float) $startTime->format('U.u');
    }

    /**
     * Build trace data for flushing.
     *
     * @param array<string, mixed> $metadata
     *
     * @return TraceData Trace data structure
     */
    private function buildTraceData(
        string $name,
        array $metadata,
        string|Prompt|Conversation $input,
        mixed $result,
        float $duration,
        ?\Throwable $error
    ): array {
        $traceData = [
            'name' => $name,
            'tags' => [$this->environment],
            'environment' => $this->environment,
            'metadata' => $metadata,
            'input' => $this->serializeInput($input),
            'duration' => $duration,
            'status' => $error ? 'error' : 'success',
        ];

        if ($result instanceof ResultInterface) {
            $traceData['output'] = $this->extractOutput($result);

            // Extract actual model from result metadata (overrides requested model)
            $resultMetadata = $result->getMetadata();
            if (isset($resultMetadata['model'])) {
                $traceData['metadata']['model'] = $resultMetadata['model'];
            }

            // Add usage data if available
            $usage = $result->getUsage();
            if ($usage !== null) {
                $traceData['usage'] = $usage->toArray();
            }
        }

        if ($error !== null) {
            $traceData['error'] = $error->getMessage();
        }

        return $traceData;
    }

    /**
     * Determine if we should trace based on enabled status and sampling rate.
     */
    private function shouldTrace(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->samplingRate >= 1.0) {
            return true;
        }

        try {
            return (mt_rand() / mt_getrandmax()) < $this->samplingRate;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Serialize input data for Langfuse tracing.
     *
     * @return InputData
     */
    private function serializeInput(string|Prompt|Conversation $input): array
    {
        if (is_string($input)) {
            return ['type' => 'string', 'content' => $input];
        }

        return [
            'type' => 'object',
            'class' => $input::class,
            'content' => $input->toArray(),
        ];
    }

    /**
     * Extract output from result for Langfuse.
     *
     * @return OutputData Structured output data
     */
    private function extractOutput(ResultInterface $result): array
    {
        return match (true) {
            $result instanceof TextResult => [
                'type' => 'text',
                'content' => $result->getContent(),
            ],
            $result instanceof ToolCallResult => [
                'type' => 'tool_call',
                'tools' => array_map(
                    static fn (ToolCall $tool) => [
                        'name' => $tool->getName(),
                        'arguments' => $tool->getArguments(),
                    ],
                    $result->getContent()
                ),
            ],
            $result instanceof ObjectResult => [
                'type' => 'object',
                'data' => $result->getContent(),
            ],
            $result instanceof BinaryResult => [
                'type' => 'binary',
                'mime_type' => $result->getMimeType(),
                'size' => mb_strlen($result->getContent()),
            ],
            $result instanceof StreamResult => [
                'type' => 'stream',
                'mime_type' => $result->getMimeType(),
            ],
            default => [
                'type' => $result::class,
                'content' => $result->getContent(),
            ],
        };
    }
}

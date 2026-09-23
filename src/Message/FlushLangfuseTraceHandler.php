<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Message;

use Lingoda\LangfuseBundle\Exception\LangfuseException;
use Lingoda\LangfuseBundle\Tracing\SyncTraceFlusher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Handler for asynchronous Langfuse trace flushing.
 * Delegates to SyncTraceFlushService to avoid code duplication.
 */
final readonly class FlushLangfuseTraceHandler
{
    public function __construct(
        private SyncTraceFlusher $syncFlushService,
        private LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * @throws \Throwable
     */
    public function __invoke(FlushLangfuseTrace $message): void
    {
        $traceData = $message->getTraceData();

        try {
            $this->logger->info('Processing async trace flush', [
                'trace_name' => $traceData['name'] ?? 'unknown',
            ]);

            $this->syncFlushService->send($traceData, $message->getUsage());

            $this->logger->info('Successfully processed async trace flush', [
                'trace_name' => $traceData['name'] ?? 'unknown',
                'has_generation' => isset($traceData['metadata']['model']),
                'has_usage' => $message->getUsage() !== null,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to flush trace to Langfuse in async handler', [
                'error' => $e->getMessage(),
                'trace_name' => $traceData['name'] ?? 'unknown',
            ]);

            // Langfuse rejected the trace itself (4xx other than 429): a retry sends the same payload, so go to the failure transport
            $status = $e instanceof LangfuseException ? $e->getCode() : 0;
            if ($status >= 400 && $status < 500 && $status !== 429) {
                throw new UnrecoverableMessageHandlingException($e->getMessage(), $status, $e);
            }

            // Transport errors, 429 and 5xx: let Messenger retry
            throw $e;
        }
    }
}

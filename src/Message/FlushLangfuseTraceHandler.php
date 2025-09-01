<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Message;

use Lingoda\LangfuseBundle\Tracing\SyncTraceFlusher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for asynchronous Langfuse trace flushing.
 * Delegates to SyncTraceFlushService to avoid code duplication.
 */
#[AsMessageHandler]
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

            $this->syncFlushService->flush($traceData, $message->getUsage());

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
            // Re-throw to allow retry via message queue
            throw $e;
        }
    }
}

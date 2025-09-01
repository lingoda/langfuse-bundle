<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tracing;

use Lingoda\AiSdk\Result\Usage;
use Lingoda\LangfuseBundle\Message\FlushLangfuseTrace;
use Lingoda\LangfuseBundle\PhpStan\Types;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Webmozart\Assert\Assert;

/**
 * Asynchronous trace flushing service.
 * Dispatches traces to a message queue for background processing.
 *
 * @phpstan-import-type TraceData from Types
 */
final readonly class AsyncTraceFlusher implements TraceFlusherInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * @param TraceData $traceData
     */
    public function flush(array $traceData, ?Usage $usage = null): void
    {
        try {
            // Dispatch trace to message queue for async processing
            $tags = $traceData['tags'] ?? [];
            Assert::isArray($tags);
            $traceData['tags'] = array_unique(array_merge($tags, ['async']));

            $message = new FlushLangfuseTrace($traceData, $usage);
            $this->messageBus->dispatch($message);

            $this->logger->debug('Trace dispatched for async flushing', [
                'trace_name' => $traceData['name'] ?? 'unknown',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to dispatch trace for async flushing', [
                'error' => $e->getMessage(),
                'trace_name' => $traceData['name'] ?? 'unknown',
            ]);
            // Silently fail - tracing should not break the application
        }
    }
}

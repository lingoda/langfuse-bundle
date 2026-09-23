<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Client;

use Dropsolid\LangFuse\Client;
use Dropsolid\LangFuse\Observability\Trace;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Trace-focused client wrapper for Langfuse operations.
 */
final readonly class TraceClient
{
    private Client $client;
    private LoggerInterface $logger;

    public function __construct(
        Client $client,
        ?LoggerInterface $logger = null
    ) {
        $this->client = $client;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Get the underlying Langfuse client.
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Create a new trace.
     *
     * @param array<string, mixed> $data
     */
    public function createTrace(array $data): ?Trace
    {
        return $this->trace($data);
    }

    /**
     * Update an existing trace.
     *
     * @param string $traceId
     * @param array<string, mixed> $data
     */
    public function updateTrace(string $traceId, array $data): void
    {
        try {
            // Since Dropsolid client doesn't have a direct update method,
            // we need to end the trace with the update data
            $this->sendEvent([
                'type' => 'trace-update',
                'traceId' => $traceId,
                'data' => $data,
            ]);

            $this->logger->debug('Trace updated', [
                'trace_id' => $traceId,
                'status' => $data['status'] ?? null,
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to update trace', [
                'trace_id' => $traceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create a new trace (legacy method name). Returns null instead of throwing: tracing is transparent to the application.
     *
     * @param array<string, mixed> $data
     */
    public function trace(array $data): ?Trace
    {
        try {
            return $this->createOrFail($data);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to create trace', [
                'name' => $data['name'] ?? 'unnamed_trace',
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Create a new trace and throw when it cannot be created.
     *
     * @param array<string, mixed> $data
     *
     * @throws \Throwable
     */
    public function createOrFail(array $data): Trace
    {
        $name = is_string($data['name'] ?? null) ? $data['name'] : 'unnamed_trace';
        $userId = is_string($data['userId'] ?? null) ? $data['userId'] : null;
        $sessionId = is_string($data['sessionId'] ?? null) ? $data['sessionId'] : null;

        $metadata = null;
        if (is_array($data['metadata'] ?? null)) {
            $metadata = [];
            foreach ($data['metadata'] as $key => $value) {
                $metadata[(string) $key] = $value;
            }
        }
        $tags = is_array($data['tags'] ?? null) ? array_values(array_filter($data['tags'], is_string(...))) : [];

        $trace = $this->client->trace(
            name: $name,
            userId: $userId,
            sessionId: $sessionId,
            metadata: $metadata,
            tags: $tags,
            version: is_string($data['version'] ?? null) ? $data['version'] : null,
            release: is_string($data['release'] ?? null) ? $data['release'] : null,
            input: $data['input'] ?? null,
            output: $data['output'] ?? null,
            environment: is_string($data['environment'] ?? null) ? $data['environment'] : null,
        );

        $this->logger->debug('Trace created', [
            'name' => $name,
            'userId' => $userId,
            'sessionId' => $sessionId,
            'tags' => $tags,
        ]);

        return $trace;
    }

    /**
     * Send event data to Langfuse.
     *
     * @param array<string, mixed> $payload
     */
    public function sendEvent(array $payload): void
    {
        try {
            $this->client->sendEvent($payload);
            $this->logger->debug('Event sent to Langfuse', [
                'event_type' => $payload['type'] ?? 'unknown',
                'event_name' => $payload['name'] ?? null,
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to send event to Langfuse', [
                'error' => $e->getMessage(),
                'event_type' => $payload['type'] ?? 'unknown',
            ]);
            // Silently fail - tracing should not break application
        }
    }

    /**
     * Flush pending events to Langfuse.
     */
    public function flush(): void
    {
        try {
            $this->flushOrFail();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to flush traces to Langfuse', [
                'error' => $e->getMessage(),
            ]);
            // Silently fail - tracing should not break application
        }
    }

    /**
     * Flush pending events and throw when Langfuse cannot be reached.
     *
     * @throws \Throwable
     */
    public function flushOrFail(): void
    {
        $this->client->flush();
        $this->logger->debug('Flushed traces to Langfuse');
    }

    /**
     * Test the connection to Langfuse.
     */
    public function testConnection(): bool
    {
        try {
            $trace = $this->client->trace(
                name: 'connection_test',
                metadata: ['test' => true, 'timestamp' => time()]
            );
            $trace->end(['status' => 'success']);
            $this->flush();

            $this->logger->info('Langfuse connection test successful');
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Langfuse connection test failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

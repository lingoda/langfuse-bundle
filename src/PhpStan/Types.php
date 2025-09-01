<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\PhpStan;

/**
 * Type definitions for trace data structures and bundle configuration.
 *
 * @phpstan-type InputData array{
 *     type: string,
 *     content: array<int|string, array<string, string>|string>|string,
 *     class?: string
 * }
 *
 * @phpstan-type OutputData array{
 *     type: string,
 *     content?: mixed,
 *     tools?: array<int, array{name: string, arguments: mixed}>,
 *     data?: mixed,
 *     mime_type?: string,
 *     size?: int
 * }
 *
 * @phpstan-type TraceData array{
 *     name: string,
 *     tags: array<int, string>,
 *     environment: string,
 *     metadata: array<string, mixed>,
 *     input: InputData,
 *     duration: float,
 *     status: 'error'|'success',
 *     output?: OutputData,
 *     usage?: array<string, mixed>,
 *     error?: string
 * }
 *
 * @phpstan-type ConnectionRetryConfig array{
 *     max_attempts: int,
 *     delay: int
 * }
 *
 * @phpstan-type ConnectionConfig array{
 *     public_key: string,
 *     secret_key: string,
 *     host: string,
 *     timeout: int,
 *     retry: ConnectionRetryConfig
 * }
 *
 * @phpstan-type AsyncFlushConfig array{
 *     enabled: bool,
 *     message_bus: string
 * }
 *
 * @phpstan-type TracingConfig array{
 *     enabled: bool,
 *     sampling_rate: float,
 *     async_flush: AsyncFlushConfig
 * }
 *
 * @phpstan-type CachingConfig array{
 *     enabled: bool,
 *     ttl: int,
 *     service: string
 * }
 *
 * @phpstan-type FallbackStorageConfig array{
 *     path?: string,
 *     service?: string
 * }
 *
 * @phpstan-type FallbackConfig array{
 *     enabled: bool,
 *     storage?: FallbackStorageConfig
 * }
 *
 * @phpstan-type PromptsConfig array{
 *     caching: CachingConfig,
 *     fallback: FallbackConfig
 * }
 *
 * @phpstan-type BundleConfig array{
 *     connection: ConnectionConfig,
 *     tracing: TracingConfig,
 *     prompts: PromptsConfig
 * }
 *
 * @codeCoverageIgnore
 * @coversNothing
 */
final class Types
{
    // This class only serves as a namespace for PHPStan type definitions
}

<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Client;

use Lingoda\AiSdk\Result\Usage;
use Lingoda\LangfuseBundle\Exception\LangfuseException;
use Lingoda\LangfuseBundle\PhpStan\Types;
use Random\RandomException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends a trace to Langfuse's OpenTelemetry endpoint (v4 ingestion) as one OTLP/HTTP JSON span.
 *
 * The span is the trace's root observation: a generation when the result named a model, a span otherwise.
 * It carries the input, output, usage and metadata, as v4 reads trace input/output from the root observation.
 *
 * @see https://langfuse.com/integrations/native/opentelemetry
 *
 * @phpstan-import-type TraceData from Types
 */
final readonly class OtlpTraceExporter
{
    private const string ENDPOINT = 'api/public/otel/v1/traces';
    private const int SPAN_KIND_INTERNAL = 1;
    private const int STATUS_OK = 1;
    private const int STATUS_ERROR = 2;
    private const string SCOPE = 'lingoda/langfuse-bundle';

    private HttpClientInterface $httpClient;

    public function __construct(
        private LangfuseConnection $connection,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    /**
     * @param TraceData $traceData
     *
     * @throws LangfuseException when Langfuse cannot be reached or rejects the trace
     * @throws RandomException when no trace id can be generated
     */
    public function export(array $traceData, ?Usage $usage = null): void
    {
        try {
            $response = $this->httpClient->request('POST', $this->connection->url(self::ENDPOINT), [
                'headers' => [
                    'Authorization' => $this->connection->authorizationHeader(),
                    'x-langfuse-ingestion-version' => '4',
                ],
                'json' => $this->payload($traceData, $usage),
                'timeout' => $this->connection->timeout,
            ]);
            $status = $response->getStatusCode();
            $body = $status >= 300 ? $response->getContent(false) : '';
        } catch (HttpClientExceptionInterface $e) {
            throw new LangfuseException('Langfuse trace export failed: ' . $e->getMessage(), 0, $e);
        }

        if ($status >= 300) {
            throw new LangfuseException(sprintf('Langfuse trace export failed (HTTP %d): %s', $status, mb_substr($body, 0, 500)), $status);
        }
    }

    /**
     * @param TraceData $traceData
     *
     * @throws RandomException when no trace id can be generated
     *
     * @return array<string, mixed>
     */
    public function payload(array $traceData, ?Usage $usage = null): array
    {
        $metadata = $traceData['metadata'];
        $model = is_string($metadata['model'] ?? null) ? $metadata['model'] : null;
        $error = $traceData['error'] ?? null;
        $endedAt = ($traceData['started_at'] ?? microtime(true) - $traceData['duration']) + $traceData['duration'];

        $attributes = [
            'langfuse.trace.name' => $traceData['name'],
            'langfuse.trace.tags' => array_values($traceData['tags']),
            'langfuse.environment' => $traceData['environment'],
            'langfuse.observation.type' => $model !== null ? 'generation' : 'span',
            'langfuse.observation.input' => self::json($traceData['input']),
        ];

        if (isset($traceData['output'])) {
            $attributes['langfuse.observation.output'] = self::json($traceData['output']);
        }

        if ($model !== null) {
            $attributes['langfuse.observation.model.name'] = $model;
        }

        if (isset($metadata['temperature'])) {
            $attributes['langfuse.observation.model.parameters'] = self::json(['temperature' => $metadata['temperature']]);
        }

        $usageDetails = $usage !== null ? self::usageDetails($usage) : null;
        if ($usageDetails !== null) {
            $attributes['langfuse.observation.usage_details'] = self::json($usageDetails);
        }

        $prompt = $metadata['langfuse_prompt'] ?? null;
        if (is_array($prompt) && is_string($prompt['name'] ?? null) && is_int($prompt['version'] ?? null)) {
            $attributes['langfuse.observation.prompt.name'] = $prompt['name'];
            $attributes['langfuse.observation.prompt.version'] = $prompt['version'];
        }
        unset($metadata['langfuse_prompt']);

        if ($error !== null) {
            $attributes['langfuse.observation.level'] = 'ERROR';
            $attributes['langfuse.observation.status_message'] = $error;
        }

        foreach ($metadata + ['status' => $traceData['status']] as $key => $value) {
            $attributes['langfuse.observation.metadata.' . $key] = is_scalar($value) ? $value : self::json($value);
        }

        $span = [
            'traceId' => $traceData['trace_id'] ?? bin2hex(random_bytes(16)),
            'spanId' => $traceData['span_id'] ?? bin2hex(random_bytes(8)),
            'name' => $traceData['name'],
            'kind' => self::SPAN_KIND_INTERNAL,
            'startTimeUnixNano' => self::nanos($endedAt - $traceData['duration']),
            'endTimeUnixNano' => self::nanos($endedAt),
            'attributes' => array_map(self::attribute(...), array_keys($attributes), array_values($attributes)),
            'status' => $error !== null ? ['code' => self::STATUS_ERROR, 'message' => $error] : ['code' => self::STATUS_OK],
        ];

        return ['resourceSpans' => [[
            'resource' => ['attributes' => [self::attribute('service.name', self::SCOPE)]],
            'scopeSpans' => [['scope' => ['name' => self::SCOPE], 'spans' => [$span]]],
        ]]];
    }

    /**
     * Langfuse's usage keys, with cached and reasoning tokens when the provider reported them.
     *
     * @return array<string, int>|null
     */
    private static function usageDetails(Usage $usage): ?array
    {
        if ($usage->promptTokens <= 0 && $usage->completionTokens <= 0 && $usage->totalTokens <= 0) {
            return null;
        }

        $details = [
            'input' => max(0, $usage->promptTokens),
            'output' => max(0, $usage->completionTokens),
            'total' => max(0, $usage->totalTokens),
        ];

        $cached = $usage->cachedTokens ?? $usage->promptDetails?->cachedTokens;
        if ($cached !== null) {
            $details['input_cached_tokens'] = max(0, $cached);
        }

        $reasoning = $usage->reasoningTokens ?? $usage->completionDetails?->reasoningTokens;
        if ($reasoning !== null) {
            $details['output_reasoning_tokens'] = max(0, $reasoning);
        }

        return $details;
    }

    /**
     * An OTLP JSON key-value attribute. Lists of strings stay arrays (tags); anything else structured is JSON.
     *
     * @return array{key: string, value: array<string, mixed>}
     */
    private static function attribute(string $key, mixed $value): array
    {
        $otlpValue = match (true) {
            is_bool($value) => ['boolValue' => $value],
            is_int($value) => ['intValue' => (string) $value],
            is_float($value) => ['doubleValue' => $value],
            is_string($value) => ['stringValue' => $value],
            is_array($value) && array_is_list($value) && array_filter($value, is_string(...)) === $value => [
                'arrayValue' => ['values' => array_map(static fn (string $item): array => ['stringValue' => $item], $value)],
            ],
            default => ['stringValue' => self::json($value)],
        };

        return ['key' => $key, 'value' => $otlpValue];
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: 'null';
    }

    /**
     * Unix time in nanoseconds as the decimal string OTLP JSON expects (floats lose precision at this size).
     */
    private static function nanos(float $seconds): string
    {
        $whole = (int) floor($seconds);
        $micros = (int) round(($seconds - $whole) * 1_000_000);
        if ($micros === 1_000_000) {
            ++$whole;
            $micros = 0;
        }

        return sprintf('%d%06d000', $whole, $micros);
    }
}

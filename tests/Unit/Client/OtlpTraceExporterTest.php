<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Client;

use Lingoda\AiSdk\Result\TokenDetails;
use Lingoda\AiSdk\Result\Usage;
use Lingoda\LangfuseBundle\Client\LangfuseConnection;
use Lingoda\LangfuseBundle\Client\OtlpTraceExporter;
use Lingoda\LangfuseBundle\Exception\LangfuseException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OtlpTraceExporterTest extends TestCase
{
    private LangfuseConnection $connection;

    protected function setUp(): void
    {
        $this->connection = new LangfuseConnection('https://cloud.langfuse.com/', 'pk-test', 'sk-test', 7);
    }

    public function testExportPostsOneOtlpJsonSpanWithTheV4Header(): void
    {
        $captured = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = [$method, $url, $options];

            return new MockResponse('{}', ['http_code' => 200]);
        });

        (new OtlpTraceExporter($this->connection, $httpClient))->export($this->traceData());

        [$method, $url, $options] = $captured;
        self::assertSame('POST', $method);
        self::assertSame('https://cloud.langfuse.com/api/public/otel/v1/traces', $url);
        self::assertContains('Authorization: Basic cGstdGVzdDpzay10ZXN0', $options['headers']);
        self::assertContains('x-langfuse-ingestion-version: 4', $options['headers']);
        self::assertContains('Content-Type: application/json', $options['headers']);
        self::assertSame(7.0, (float) $options['timeout']);

        $body = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $body['resourceSpans'][0]['scopeSpans'][0]['spans']);
    }

    public function testGenerationSpanCarriesModelUsagePromptAndContent(): void
    {
        $span = $this->span($this->traceData([
            'metadata' => [
                'provider' => 'AWS Bedrock',
                'model' => 'eu.amazon.nova-2-lite-v1:0',
                'temperature' => 0.0,
                'langfuse_prompt' => ['name' => 'mnr-voucher-fields', 'version' => 3],
            ],
            'started_at' => 1790000000.25,
            'duration' => 1.5,
        ]), new Usage(900, 12, 912, completionDetails: new TokenDetails(reasoningTokens: 4), cachedTokens: 100));
        $attributes = $this->attributes($span);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $span['traceId']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $span['spanId']);
        self::assertSame('ai-completion', $span['name']);
        self::assertSame('1790000000250000000', $span['startTimeUnixNano']);
        self::assertSame('1790000001750000000', $span['endTimeUnixNano']);
        self::assertSame(['code' => 1], $span['status']);

        self::assertSame(['stringValue' => 'ai-completion'], $attributes['langfuse.trace.name']);
        self::assertSame(['arrayValue' => ['values' => [['stringValue' => 'prod']]]], $attributes['langfuse.trace.tags']);
        self::assertSame(['stringValue' => 'prod'], $attributes['langfuse.environment']);
        self::assertSame(['stringValue' => 'generation'], $attributes['langfuse.observation.type']);
        self::assertSame(['stringValue' => 'eu.amazon.nova-2-lite-v1:0'], $attributes['langfuse.observation.model.name']);
        self::assertSame(['stringValue' => '{"temperature":0.0}'], $attributes['langfuse.observation.model.parameters']);
        self::assertSame(['stringValue' => '{"type":"string","content":"prompt"}'], $attributes['langfuse.observation.input']);
        self::assertSame(['stringValue' => '{"type":"text","content":"AI response"}'], $attributes['langfuse.observation.output']);
        self::assertSame(
            ['input' => 900, 'output' => 12, 'total' => 912, 'input_cached_tokens' => 100, 'output_reasoning_tokens' => 4],
            json_decode($attributes['langfuse.observation.usage_details']['stringValue'], true)
        );
        self::assertSame(['stringValue' => 'mnr-voucher-fields'], $attributes['langfuse.observation.prompt.name']);
        self::assertSame(['intValue' => '3'], $attributes['langfuse.observation.prompt.version']);
        self::assertSame(['stringValue' => 'AWS Bedrock'], $attributes['langfuse.observation.metadata.provider']);
        self::assertSame(['stringValue' => 'success'], $attributes['langfuse.observation.metadata.status']);
        self::assertArrayNotHasKey('langfuse.observation.metadata.langfuse_prompt', $attributes);
        self::assertArrayNotHasKey('langfuse.observation.level', $attributes);
    }

    public function testSpanWithoutModelAndWithoutUsage(): void
    {
        $attributes = $this->attributes($this->span($this->traceData(['metadata' => ['input_length' => 12]]), new Usage(0, 0, 0)));

        self::assertSame(['stringValue' => 'span'], $attributes['langfuse.observation.type']);
        self::assertArrayNotHasKey('langfuse.observation.model.name', $attributes);
        self::assertArrayNotHasKey('langfuse.observation.usage_details', $attributes);
        self::assertSame(['intValue' => '12'], $attributes['langfuse.observation.metadata.input_length']);
    }

    public function testErrorIsTheObservationLevelAndSpanStatus(): void
    {
        $traceData = $this->traceData(['status' => 'error', 'error' => 'Rate limit exceeded', 'metadata' => ['model' => 'gpt-4o', 'nested' => ['a' => true]]]);
        unset($traceData['output']);
        $span = $this->span($traceData);
        $attributes = $this->attributes($span);

        self::assertSame(['code' => 2, 'message' => 'Rate limit exceeded'], $span['status']);
        self::assertSame(['stringValue' => 'ERROR'], $attributes['langfuse.observation.level']);
        self::assertSame(['stringValue' => 'Rate limit exceeded'], $attributes['langfuse.observation.status_message']);
        self::assertArrayNotHasKey('langfuse.observation.output', $attributes);
        self::assertSame(['stringValue' => '{"a":true}'], $attributes['langfuse.observation.metadata.nested']);
    }

    public function testRedactedTraceSendsNoContent(): void
    {
        $traceData = $this->traceData(['input' => ['type' => 'redacted'], 'metadata' => ['model' => 'eu.amazon.nova-2-lite-v1:0']]);
        unset($traceData['output']);
        $attributes = $this->attributes($this->span($traceData));

        self::assertSame(['stringValue' => '{"type":"redacted"}'], $attributes['langfuse.observation.input']);
        self::assertArrayNotHasKey('langfuse.observation.output', $attributes);
    }

    public function testRejectedExportThrowsWithTheStatus(): void
    {
        $exporter = new OtlpTraceExporter($this->connection, new MockHttpClient(new MockResponse('{"message":"Invalid credentials"}', ['http_code' => 401])));

        $this->expectException(LangfuseException::class);
        $this->expectExceptionCode(401);
        $this->expectExceptionMessage('Langfuse trace export failed (HTTP 401): {"message":"Invalid credentials"}');

        $exporter->export($this->traceData());
    }

    public function testTransportFailureThrows(): void
    {
        $exporter = new OtlpTraceExporter($this->connection, new MockHttpClient(static fn () => throw new TransportException('Could not resolve host')));

        $this->expectException(LangfuseException::class);
        $this->expectExceptionMessage('Langfuse trace export failed: Could not resolve host');

        $exporter->export($this->traceData());
    }

    public function testDefaultsToItsOwnHttpClient(): void
    {
        $payload = (new OtlpTraceExporter($this->connection))->payload($this->traceData());

        self::assertSame([['key' => 'service.name', 'value' => ['stringValue' => 'lingoda/langfuse-bundle']]], $payload['resourceSpans'][0]['resource']['attributes']);
    }

    /**
     * @param array<string, mixed> $traceData
     *
     * @return array<string, mixed>
     */
    private function span(array $traceData, ?Usage $usage = null): array
    {
        /** @var array{name: string, tags: array<int, string>, environment: string, metadata: array<string, mixed>, input: array{type: string}, duration: float, status: 'error'|'success'} $traceData */
        $payload = (new OtlpTraceExporter($this->connection, new MockHttpClient()))->payload($traceData, $usage);
        // Round-trip through JSON, as Langfuse receives it
        $decoded = json_decode((string) json_encode($payload), true, 512, JSON_THROW_ON_ERROR);

        return $decoded['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
    }

    /**
     * @param array<string, mixed> $span
     *
     * @return array<string, array<string, mixed>>
     */
    private function attributes(array $span): array
    {
        return array_column($span['attributes'], 'value', 'key');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{name: string, tags: array<int, string>, environment: string, metadata: array<string, mixed>, input: array{type: string, content?: array<mixed>|string, class?: string}, duration: float, status: 'error'|'success', output?: array{type: string, content?: mixed}, error?: string}
     */
    private function traceData(array $overrides = []): array
    {
        /** @var array{name: string, tags: array<int, string>, environment: string, metadata: array<string, mixed>, input: array{type: string, content?: array<mixed>|string, class?: string}, duration: float, status: 'error'|'success', output?: array{type: string, content?: mixed}, error?: string} $data */
        $data = array_merge([
            'name' => 'ai-completion',
            'tags' => ['prod'],
            'environment' => 'prod',
            'metadata' => [],
            'input' => ['type' => 'string', 'content' => 'prompt'],
            'duration' => 0.5,
            'status' => 'success',
            'output' => ['type' => 'text', 'content' => 'AI response'],
        ], $overrides);

        return $data;
    }

    public function testSubMicrosecondRoundingCarriesIntoTheNextSecond(): void
    {
        $span = $this->span($this->traceData(['started_at' => 1789999999.9999996, 'duration' => 0.0]));

        self::assertSame('1790000000000000000', $span['startTimeUnixNano']);
    }

    public function testRetriedTraceKeepsItsIds(): void
    {
        $span = $this->span($this->traceData(['trace_id' => str_repeat('ab', 16), 'span_id' => str_repeat('cd', 8)]));

        self::assertSame(str_repeat('ab', 16), $span['traceId']);
        self::assertSame(str_repeat('cd', 8), $span['spanId']);
    }
}

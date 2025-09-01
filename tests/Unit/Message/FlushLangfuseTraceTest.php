<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Message;

use Lingoda\AiSdk\Result\Usage;
use Lingoda\LangfuseBundle\Message\FlushLangfuseTrace;
use PHPUnit\Framework\TestCase;

final class FlushLangfuseTraceTest extends TestCase
{
    public function testMessageWithTraceDataOnly(): void
    {
        $traceData = [
            'name' => 'test-operation',
            'tags' => ['test'],
            'metadata' => ['model' => 'gpt-4'],
            'input' => 'test input',
            'output' => 'test output'
        ];

        $message = new FlushLangfuseTrace($traceData);

        self::assertEquals($traceData, $message->getTraceData());
        self::assertNull($message->getUsage());
    }

    public function testMessageWithTraceDataAndUsage(): void
    {
        $traceData = [
            'name' => 'ai-operation',
            'tags' => ['production'],
            'duration' => 2.5,
            'status' => 'success'
        ];
        $usage = new Usage(10, 20, 30);

        $message = new FlushLangfuseTrace($traceData, $usage);

        self::assertEquals($traceData, $message->getTraceData());
        self::assertSame($usage, $message->getUsage());
    }

    public function testMessageWithEmptyTraceData(): void
    {
        $traceData = [];
        $message = new FlushLangfuseTrace($traceData);

        self::assertEquals([], $message->getTraceData());
        self::assertNull($message->getUsage());
    }

    public function testMessageWithComplexTraceData(): void
    {
        $traceData = [
            'name' => 'complex-operation',
            'tags' => ['async', 'production'],
            'environment' => 'prod',
            'metadata' => [
                'user_id' => '123',
                'session_id' => 'abc-def',
                'model' => 'gpt-4-turbo',
                'temperature' => 0.7,
                'nested' => [
                    'data' => 'value',
                    'array' => [1, 2, 3]
                ]
            ],
            'input' => [
                'type' => 'object',
                'content' => ['messages' => [['role' => 'user', 'content' => 'Hello']]]
            ],
            'output' => [
                'type' => 'text',
                'content' => 'Hello! How can I help you today?'
            ],
            'usage' => [
                'prompt_tokens' => 15,
                'completion_tokens' => 8,
                'total_tokens' => 23
            ],
            'duration' => 1.234,
            'status' => 'success'
        ];
        $usage = new Usage(15, 8, 23);

        $message = new FlushLangfuseTrace($traceData, $usage);

        self::assertEquals($traceData, $message->getTraceData());
        self::assertSame($usage, $message->getUsage());
        self::assertEquals('complex-operation', $message->getTraceData()['name']);
        self::assertEquals(['async', 'production'], $message->getTraceData()['tags']);
    }
}

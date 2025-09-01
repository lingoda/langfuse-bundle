<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Deserialization;

use Lingoda\LangfuseBundle\Deserialization\PromptDeserializer;
use Lingoda\LangfuseBundle\Exception\DeserializationException;
use PHPUnit\Framework\TestCase;

final class PromptDeserializerTest extends TestCase
{
    private PromptDeserializer $deserializer;

    protected function setUp(): void
    {
        $this->deserializer = new PromptDeserializer();
    }

    public function testDeserializeValidPromptData(): void
    {
        $promptData = [
            'name' => 'test-prompt',
            'version' => 1,
            'prompt' => [
                ['role' => 'system', 'content' => 'You are a helpful assistant'],
                ['role' => 'user', 'content' => 'Hello, how are you?']
            ],
            'config' => ['temperature' => 0.7]
        ];

        $result = $this->deserializer->deserialize($promptData);

        // Verify the conversation structure
        $conversationArray = $result->toArray();
        self::assertCount(2, $conversationArray);

        // Verify system message
        self::assertEquals('system', $conversationArray[0]['role']);
        self::assertEquals('You are a helpful assistant', $conversationArray[0]['content']);

        // Verify user message
        self::assertEquals('user', $conversationArray[1]['role']);
        self::assertEquals('Hello, how are you?', $conversationArray[1]['content']);
    }

    public function testDeserializeSingleMessage(): void
    {
        $promptData = [
            'prompt' => [
                ['role' => 'user', 'content' => 'What is the weather like?']
            ]
        ];

        $result = $this->deserializer->deserialize($promptData);

        // Verify single user message
        $conversationArray = $result->toArray();
        self::assertCount(1, $conversationArray);
        self::assertEquals('user', $conversationArray[0]['role']);
        self::assertEquals('What is the weather like?', $conversationArray[0]['content']);
    }

    public function testDeserializeComplexMessages(): void
    {
        $promptData = [
            'prompt' => [
                [
                    'role' => 'system',
                    'content' => 'You are an AI assistant that helps with coding questions.'
                ],
                [
                    'role' => 'user',
                    'content' => 'How do I write a function in Python?'
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Here is how you can write a function in Python:\n\n```python\ndef my_function():\n    return "Hello World"\n```'
                ]
            ]
        ];

        $result = $this->deserializer->deserialize($promptData);

        // Verify complex conversation structure (order: system, assistant, user)
        $conversationArray = $result->toArray();
        self::assertCount(3, $conversationArray);

        // Verify system message
        self::assertEquals('system', $conversationArray[0]['role']);
        self::assertEquals('You are an AI assistant that helps with coding questions.', $conversationArray[0]['content']);

        // Verify assistant message (comes before user message in toArray() output)
        self::assertEquals('assistant', $conversationArray[1]['role']);
        self::assertEquals('Here is how you can write a function in Python:\n\n```python\ndef my_function():\n    return "Hello World"\n```', $conversationArray[1]['content']);

        // Verify user message (always last)
        self::assertEquals('user', $conversationArray[2]['role']);
        self::assertEquals('How do I write a function in Python?', $conversationArray[2]['content']);
    }

    public function testDeserializeThrowsExceptionWhenPromptFieldMissing(): void
    {
        $promptData = [
            'name' => 'invalid-prompt',
            'config' => ['temperature' => 0.5]
        ];

        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('Invalid prompt data: missing "prompt" field or not an array');

        $this->deserializer->deserialize($promptData);
    }

    public function testDeserializeThrowsExceptionWhenPromptFieldIsNotArray(): void
    {
        $promptData = [
            'name' => 'invalid-prompt',
            'prompt' => 'this is not an array'
        ];

        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('Invalid prompt data: missing "prompt" field or not an array');

        $this->deserializer->deserialize($promptData);
    }

    public function testDeserializeThrowsExceptionWhenPromptFieldIsEmpty(): void
    {
        $promptData = [
            'name' => 'empty-prompt',
            'prompt' => []
        ];

        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('No valid prompts found in data');

        $this->deserializer->deserialize($promptData);
    }

    public function testDeserializeThrowsExceptionWhenPromptFieldIsNull(): void
    {
        $promptData = [
            'name' => 'null-prompt',
            'prompt' => null
        ];

        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('Invalid prompt data: missing "prompt" field or not an array');

        $this->deserializer->deserialize($promptData);
    }

    public function testDeserializeHandlesConversationCreationFailure(): void
    {
        // Create prompt data that will cause Conversation::fromArray to fail
        $promptData = [
            'prompt' => [
                ['invalid' => 'message structure'] // Missing required 'role' field
            ]
        ];

        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('Failed to create conversation from prompt data:');

        $this->deserializer->deserialize($promptData);
    }

    public function testDeserializeWithAdditionalMetadata(): void
    {
        $promptData = [
            'name' => 'metadata-prompt',
            'version' => 5,
            'label' => 'production',
            'prompt' => [
                ['role' => 'user', 'content' => 'Test message']
            ],
            'config' => [
                'model' => 'gpt-4',
                'temperature' => 0.8,
                'max_tokens' => 1000
            ],
            'metadata' => [
                'author' => 'test-user',
                'created_at' => '2024-01-01T00:00:00Z'
            ]
        ];

        $result = $this->deserializer->deserialize($promptData);

        // Verify metadata doesn't affect conversation structure (only prompt content matters)
        $conversationArray = $result->toArray();
        self::assertCount(1, $conversationArray);
        self::assertEquals('user', $conversationArray[0]['role']);
        self::assertEquals('Test message', $conversationArray[0]['content']);
    }

    public function testDeserializeWithEmptyPromptData(): void
    {
        $promptData = [];

        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('Invalid prompt data: missing "prompt" field or not an array');

        $this->deserializer->deserialize($promptData);
    }
}

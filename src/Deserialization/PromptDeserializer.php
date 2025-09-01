<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Deserialization;

use Lingoda\AiSdk\Prompt\Conversation;
use Lingoda\LangfuseBundle\Exception\DeserializationException;

/**
 * Service for deserializing Langfuse prompt data into AI SDK Conversation objects.
 * Converts Langfuse API response format to structured Conversation with typed prompts.
 */
final readonly class PromptDeserializer
{
    /**
     * Deserialize Langfuse prompt data into a Conversation object.
     *
     * @param array<string, mixed> $promptData Langfuse prompt data from API
     * @throws DeserializationException
     */
    public function deserialize(array $promptData): Conversation
    {
        if (!isset($promptData['prompt']) || !is_array($promptData['prompt'])) {
            throw new DeserializationException('Invalid prompt data: missing "prompt" field or not an array');
        }

        if (empty($promptData['prompt'])) {
            throw new DeserializationException('No valid prompts found in data');
        }

        // Transform Langfuse format to Conversation::fromArray() format
        $conversationData = ['messages' => $promptData['prompt']];

        try {
            return Conversation::fromArray($conversationData);
        } catch (\Exception $e) {
            throw new DeserializationException('Failed to create conversation from prompt data: ' . $e->getMessage(), 0, $e);
        }
    }
}

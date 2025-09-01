<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Prompt;

use Lingoda\AiSdk\Prompt\Conversation;
use Lingoda\LangfuseBundle\Exception\LangfuseException;

/**
 * Registry for prompt retrieval and deserialization.
 * Provides high-level access to prompts as Conversation objects.
 */
interface PromptRegistryInterface
{
    /**
     * Get and deserialize a prompt into a Conversation object.
     *
     * @param string $name Prompt name
     * @param int|null $version Prompt version (null for latest)
     * @param string|null $label Prompt label
     * @param bool $useCache Whether to use cache (default: true)
     * @throws LangfuseException When prompt cannot be loaded or deserialized
     */
    public function get(string $name, ?int $version = null, ?string $label = null, bool $useCache = true): Conversation;

    /**
     * Check if a prompt exists.
     */
    public function has(string $name, ?int $version = null, ?string $label = null): bool;

    /**
     * Get raw prompt data without deserialization.
     * Useful for debugging or custom processing.
     *
     * @return array<string, mixed>
     */
    public function getRawPrompt(string $name, ?int $version = null, ?string $label = null, bool $useCache = true): array;
}

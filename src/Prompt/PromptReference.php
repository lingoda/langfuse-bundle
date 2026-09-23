<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Prompt;

/**
 * A Langfuse prompt at a resolved version, passed to ask() as the langfuse_prompt option to link the generation to it.
 */
final readonly class PromptReference
{
    public function __construct(
        public string $name,
        public int $version,
    ) {
    }
}

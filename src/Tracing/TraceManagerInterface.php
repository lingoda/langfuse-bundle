<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tracing;

use Lingoda\AiSdk\Prompt\Conversation;
use Lingoda\AiSdk\Prompt\Prompt;
use Lingoda\AiSdk\Result\ResultInterface;

interface TraceManagerInterface
{
    /**
     * Generic trace method for any AI operation.
     *
     * @template TCallable of ResultInterface
     *
     * @param array<string, mixed> $metadata
     * @param string|array<string, mixed>|Prompt|Conversation $input A prompt, or a structured request such as a decision
     * @param callable(): TCallable $callable The operation to trace
     * @param bool $recordContent false keeps name, model, usage, duration and status, but no input, output or error text
     *
     * @throws \Throwable
     *
     * @return TCallable
     */
    public function trace(string $name, array $metadata, string|array|Prompt|Conversation $input, callable $callable, bool $recordContent = true): ResultInterface;

    /**
     * Check if tracing is enabled.
     */
    public function isEnabled(): bool;
}
